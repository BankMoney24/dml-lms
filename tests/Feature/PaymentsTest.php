<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Learning\LearningService;
use App\Modules\Payments\Http\PaymentAdminController;
use App\Modules\Payments\PaystackApi;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;
use Tests\Support\FakePaystack;

/**
 * Paid courses: access only after Paystack itself confirms the payment
 * (PAY-002, PAY-003), and the school's secret key never leaves the server (PAY-005).
 */
final class PaymentsTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const KEY = 'sk_test_abcdefghijklmnopqrstuvwxyz012345';

    private FakePaystack $paystack;
    private Tenant $tenant;
    private User $learner;
    private Course $course;

    protected function setUp(): void
    {
        $this->paystack = new FakePaystack();
        $this->overrides = [PaystackApi::class => $this->paystack];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->learner = $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'other@school.test', Role::Learner);
        $course = $this->makeCourse($this->tenant, $teacher, 'networking')['course'];
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $course->id, 1500000, 'NGN');
        $this->course = $this->app->get(CourseRepository::class)->findById($this->tenant->id, $course->id) ?? $course;
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    private function connect(): void
    {
        $this->signIn('admin@school.test');
        $this->assertRedirectTo('/school/payments', $this->post(self::HOST, '/school/payments/connect', ['secret' => self::KEY, 'currency' => 'NGN']));
    }

    private function enrolments(?int $userId = null): int
    {
        return (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments WHERE user_id = ?', [$userId ?? $this->learner->id]);
    }

    /** Starts a checkout as the learner and returns the payment reference. */
    private function startCheckout(): string
    {
        $this->signIn('learner@school.test');
        $response = $this->post(self::HOST, '/courses/networking/pay');
        $this->assertSame(303, $response->status);
        $this->assertSame('https://checkout.paystack.com/abc123xyz', $response->header('Location'));

        return (string) $this->db()->fetchValue('SELECT reference FROM payments ORDER BY id DESC LIMIT 1');
    }

    /** @param array<string, mixed> $data */
    private function webhook(array $data, ?string $signWith = self::KEY): Response
    {
        $body = (string) json_encode(['event' => 'charge.success', 'data' => $data]);
        $headers = ['content-type' => 'application/json'];
        if ($signWith !== null) {
            $headers['x-paystack-signature'] = hash_hmac('sha512', $body, $signWith);
        }

        return $this->send(new Request('POST', '/webhooks/paystack', self::HOST, headers: $headers, rawBody: $body));
    }

    /** @return array<string, mixed> */
    private function charge(string $reference, int $amount = 1500000, string $currency = 'NGN'): array
    {
        return ['reference' => $reference, 'status' => 'success', 'amount' => $amount, 'currency' => $currency, 'id' => 4099260516, 'channel' => 'bank_transfer'];
    }

    public function testAPaidCourseCannotBeEnrolledInWithoutPaying(): void
    {
        $this->signIn('learner@school.test');
        $page = $this->get(self::HOST, '/courses/networking')->body;
        $this->assertStringContainsString('₦15,000', $page);
        $this->assertStringContainsString('does not take payments online yet', $page, 'Paystack is not connected');
        $this->assertStringNotContainsString('Enrol and start', $page);

        $this->assertRedirectTo('/courses/networking', $this->post(self::HOST, '/courses/networking/enrol'));
        $this->assertSame(0, $this->enrolments());
        $this->assertRedirectTo('/courses/networking#start', $this->get(self::HOST, '/courses/networking/lessons/' . $this->firstLessonId()), 'no lesson without paying');

        // The rule lives in the service too, not just the button.
        $membership = $this->app->get(MembershipRepository::class)->find($this->tenant->id, $this->learner->id);
        $this->expectExceptionMessage('This course is paid');
        $this->app->get(LearningService::class)->enrol($this->course, new CurrentUser($this->learner, $membership));
    }

    public function testCourseBuildersCanLookInsideWithoutPaying(): void
    {
        $this->signIn('admin@school.test');
        $this->assertStringContainsString('Enrol and start', $this->get(self::HOST, '/courses/networking')->body);
        $this->post(self::HOST, '/courses/networking/enrol');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments'));
    }

    public function testCheckoutThenAConfirmedReturnEnrolsTheLearner(): void
    {
        $this->connect();
        $reference = $this->startCheckout();
        $this->assertMatchesRegularExpression('/^DML-\d+-[A-F0-9]{16}$/', $reference);
        $asked = $this->paystack->initialized[0];
        $this->assertSame([1500000, 'NGN', 'learner@school.test', self::KEY], [$asked['amount'], $asked['currency'], $asked['email'], $asked['secret']]);
        $this->assertSame('http://school.test/payments/paystack/callback', $asked['callback']);
        $this->assertSame(0, $this->enrolments(), 'starting a checkout grants nothing');

        $back = $this->send(new Request('GET', '/payments/paystack/callback', self::HOST, ['reference' => $reference]));
        $this->assertRedirectTo('/courses/networking', $back);
        $this->assertSame([$reference], $this->paystack->verified, 'the server asked Paystack itself');
        $this->assertSame(1, $this->enrolments());
        $row = $this->db()->fetchOne('SELECT status, provider_transaction_id, channel FROM payments');
        $this->assertSame(['paid', '4099260516', 'card'], [$row['status'] ?? null, $row['provider_transaction_id'] ?? null, $row['channel'] ?? null]);
        $this->assertStringContainsString('Payment of ₦15,000 received', $this->get(self::HOST, '/courses/networking')->body);
        $this->assertStringContainsString($reference, $this->get(self::HOST, '/account')->body, 'listed under Your payments');

        // Coming back again (refresh) changes nothing.
        $this->send(new Request('GET', '/payments/paystack/callback', self::HOST, ['reference' => $reference]));
        $this->assertSame(1, $this->enrolments());
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'payment.paid'"));
    }

    public function testReturningWithoutAConfirmedPaymentGrantsNothing(): void
    {
        $this->connect();
        $reference = $this->startCheckout();
        $this->paystack->verifyAnswer = ['status' => 'abandoned', 'amount' => null, 'currency' => null];
        $page = $this->send(new Request('GET', '/payments/paystack/callback', self::HOST, ['reference' => $reference, 'status' => 'success']));
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Confirming your payment', $page->body);
        $this->assertSame(0, $this->enrolments(), 'the browser saying "success" is not enough');
        $this->assertStringContainsString('You started a payment of ₦15,000', $this->get(self::HOST, '/courses/networking')->body);

        $this->paystack->verifyAnswer = ['status' => 'failed', 'amount' => null, 'currency' => null];
        $page = $this->send(new Request('GET', '/payments/paystack/callback', self::HOST, ['reference' => $reference]));
        $this->assertStringContainsString('did not go through', $page->body);
        $this->assertSame('failed', $this->db()->fetchValue('SELECT status FROM payments'));
    }

    public function testSomeoneElsesReferenceIsNotFound(): void
    {
        $this->connect();
        $reference = $this->startCheckout();
        $this->signIn('other@school.test');
        $this->assertSame(404, $this->send(new Request('GET', '/payments/paystack/callback', self::HOST, ['reference' => $reference]))->status);
        $this->assertSame([], $this->paystack->verified);
    }

    public function testAForgedWebhookIsRefused(): void
    {
        $this->connect();
        $reference = $this->startCheckout();
        $this->assertSame(401, $this->webhook($this->charge($reference), null)->status, 'no signature');
        $this->assertSame(401, $this->webhook($this->charge($reference), 'sk_test_someone_elses_key_0000000000')->status, 'wrong key');
        $this->assertSame('pending', $this->db()->fetchValue('SELECT status FROM payments'));
        $this->assertSame(0, $this->enrolments());
    }

    public function testASignedWebhookEnrolsOnceHoweverOftenItArrives(): void
    {
        $this->connect();
        $reference = $this->startCheckout();
        $this->newBrowser();
        for ($i = 0; $i < 3; $i++) {
            $response = $this->webhook($this->charge($reference));
            $this->assertSame(200, $response->status);
            $this->assertNull($response->header('Set-Cookie'), 'no session for webhooks');
        }
        $this->assertSame('paid', $this->db()->fetchValue('SELECT status FROM payments'));
        $this->assertSame(1, $this->enrolments());
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM payment_events WHERE outcome = 'paid'"));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'payment.paid'"));

        // The learner coming back afterwards just lands on the course.
        $this->signIn('learner@school.test');
        $this->assertRedirectTo('/courses/networking', $this->send(new Request('GET', '/payments/paystack/callback', self::HOST, ['reference' => $reference])));
    }

    public function testAWrongAmountOrCurrencyIsNotAccepted(): void
    {
        $this->connect();
        $reference = $this->startCheckout();
        $this->webhook($this->charge($reference, 150000));
        $this->assertSame('failed', $this->db()->fetchValue('SELECT status FROM payments'));
        $this->assertSame(0, $this->enrolments());

        $reference = $this->startCheckout();
        $this->webhook($this->charge($reference, 1500000, 'USD'));
        $this->assertSame(0, $this->enrolments());
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM payment_events WHERE outcome = 'mismatch'"));
    }

    public function testOtherEventsAndUnknownReferencesAreAcknowledgedButChangeNothing(): void
    {
        $this->connect();
        $body = (string) json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'x']]);
        $response = $this->send(new Request('POST', '/webhooks/paystack', self::HOST, headers: [
            'content-type' => 'application/json', 'x-paystack-signature' => hash_hmac('sha512', $body, self::KEY),
        ], rawBody: $body));
        $this->assertSame(200, $response->status);
        $this->assertSame(200, $this->webhook($this->charge('DML-1-0000000000000000'))->status);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments'));
    }

    public function testCheckoutProblemsAreExplainedAndChargeNothing(): void
    {
        $this->connect();
        $this->paystack->down = true;
        $this->signIn('learner@school.test');
        $this->assertRedirectTo('/courses/networking', $this->post(self::HOST, '/courses/networking/pay'));
        $this->assertStringContainsString('could not be opened just now', $this->get(self::HOST, '/courses/networking')->body);
        $this->assertSame('failed', $this->db()->fetchValue('SELECT status FROM payments'));
    }

    public function testTheSecretKeyIsCheckedStoredSealedAndNeverShown(): void
    {
        $this->signIn('admin@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/school/payments/connect', ['secret' => 'pk_live_123', 'currency' => 'NGN'])->status);
        $this->paystack->acceptsKey = false;
        $refused = $this->post(self::HOST, '/school/payments/connect', ['secret' => self::KEY, 'currency' => 'NGN']);
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('did not accept that key', $refused->body);
        $this->assertStringNotContainsString(self::KEY, $refused->body, 'not even to refill the form');

        $this->paystack->acceptsKey = true;
        $this->post(self::HOST, '/school/payments/connect', ['secret' => self::KEY, 'currency' => 'NGN']);
        $sealed = (string) $this->db()->fetchValue('SELECT secret_sealed FROM payment_accounts');
        $this->assertStringNotContainsString('abcdefghijklmnop', $sealed);
        $page = $this->get(self::HOST, '/school/payments')->body;
        $this->assertStringContainsString('Test mode', $page);
        $this->assertStringContainsString('http://school.test/webhooks/paystack', $page);
        $this->assertStringNotContainsString('abcdefghijklmnop', $page);
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'payments.connected'"));
        $this->assertStringNotContainsString('abcdefghijklmnop', (string) $this->db()->fetchValue('SELECT GROUP_CONCAT(metadata) FROM audit_events'));

        $this->signIn('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/payments')->status);
    }

    public function testOnlyAdministratorsSetPrices(): void
    {
        $this->signIn('teacher@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['price' => '0', 'currency' => 'NGN'])->status);
        $this->assertStringContainsString('A school administrator sets prices', $this->get(self::HOST, '/teach/courses/' . $this->course->id)->body);

        $this->signIn('admin@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['price' => '5', 'currency' => 'NGN'])->status, 'below the minimum');
        $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['price' => '25,000', 'currency' => 'GHS']);
        $this->assertSame(['2500000', 'GHS'], array_map('strval', array_values($this->db()->fetchOne('SELECT price_minor, currency FROM courses') ?? [])));
        $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['price' => '', 'currency' => 'GHS']);
        $this->assertNull($this->db()->fetchValue('SELECT price_minor FROM courses'));

        $this->signIn('learner@school.test');
        $this->assertStringContainsString('Enrol and start', $this->get(self::HOST, '/courses/networking')->body, 'free again');
    }

    public function testAnAdministratorCanRecordAPaymentByHand(): void
    {
        $this->db()->execute("UPDATE users SET display_name = '=HYPERLINK(\"http://x\")' WHERE id = ?", [$this->learner->id]);
        $this->signIn('admin@school.test');
        $bad = $this->post(self::HOST, '/school/payments/manual', ['email' => 'nobody@school.test', 'course_id' => (string) $this->course->id, 'amount' => '15000', 'note' => '']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('No one with that email', $bad->body);

        $ok = $this->post(self::HOST, '/school/payments/manual', [
            'email' => 'learner@school.test', 'course_id' => (string) $this->course->id, 'amount' => '15,000', 'note' => 'Bank transfer, teller 004512',
        ]);
        $this->assertRedirectTo('/school/payments', $ok);
        $this->assertSame(1, $this->enrolments());
        $page = $this->get(self::HOST, '/school/payments')->body;
        $this->assertStringContainsString('Recorded by hand', $page);
        $this->assertStringContainsString('₦15,000', $page);

        $csv = $this->get(self::HOST, '/school/payments.csv');
        $this->assertSame('text/csv; charset=utf-8', $csv->header('Content-Type'));
        $this->assertStringContainsString('Bank transfer, teller 004512', $csv->body);
        $this->assertStringContainsString("'=HYPERLINK", $csv->body, 'formulas are neutralised');
        $this->assertSame("'@SUM(A1)", PaymentAdminController::cell('@SUM(A1)'));
        $this->assertSame('Plain', PaymentAdminController::cell('Plain'));

        $this->assertSame(422, $this->post(self::HOST, '/school/payments/manual', [
            'email' => 'learner@school.test', 'course_id' => (string) $this->course->id, 'amount' => '15000', 'note' => 'Again by mistake',
        ])->status, 'already paid');
    }

    private function firstLessonId(): int
    {
        return (int) $this->db()->fetchValue('SELECT MIN(id) FROM lessons WHERE course_id = ?', [$this->course->id]);
    }
}
