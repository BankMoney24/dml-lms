<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Payments\PaystackApi;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;
use Tests\Support\FakePaystack;

/** ADR 0026: subscribe to a course; free starts at once, paid starts after the payment is confirmed. */
final class SubscribeTest extends AppTestCase
{
    private const HOST = 'school.test';

    private FakePaystack $paystack;
    private Tenant $tenant;
    private Course $paid;

    protected function setUp(): void
    {
        $this->paystack = new FakePaystack();
        $this->overrides = [PaystackApi::class => $this->paystack];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'bo@school.test', Role::Learner);
        $this->makeCourse($this->tenant, $teacher, 'free-course');
        $paid = $this->makeCourse($this->tenant, $teacher, 'paid-course')['course'];
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $paid->id, 1500000, 'NGN');
        $this->paid = $paid;
        $this->app->get(TenantRepository::class)->updateJoining($this->tenant->id, 'open', null, true);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function enrolled(string $email, string $slug): bool
    {
        return $this->db()->fetchValue(
            'SELECT 1 FROM enrolments e JOIN users u ON u.id = e.user_id JOIN courses c ON c.id = e.course_id WHERE u.email = ? AND c.slug = ?',
            [$email, $slug],
        ) !== null;
    }

    private function claimStatus(): ?string
    {
        $v = $this->db()->fetchValue('SELECT status FROM payment_claims ORDER BY id DESC LIMIT 1');

        return $v === null ? null : (string) $v;
    }

    /** @param array<string, string> $over */
    private function claim(array $over = []): \App\Core\Http\Response
    {
        return $this->post(self::HOST, '/courses/paid-course/subscribe/transfer', $over + [
            'method' => 'transfer', 'amount' => '15,000', 'paid_on' => gmdate('Y-m-d'), 'reference' => '000013261019120000123456', 'note' => '',
        ]);
    }

    public function testVisitorsAreShownTheStepsAndSentToMakeAnAccount(): void
    {
        $page = $this->get(self::HOST, '/courses/paid-course/subscribe');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Subscribe to Course school', $page->body);
        $this->assertMatchesRegularExpression('#sub-step is-current" aria-current="step">.*?Your account#s', $page->body);
        $this->assertStringContainsString('Payment', $page->body, 'a paid course has a payment step');
        $this->assertStringContainsString('href="/join?course=paid-course"', $page->body);
        $this->assertStringContainsString('/login?next=%2Fcourses%2Fpaid-course%2Fsubscribe', $page->body);
        $this->assertStringNotContainsString('>Payment<', $this->get(self::HOST, '/courses/free-course/subscribe')->body, 'free courses skip it');
        $this->assertStringContainsString('href="/courses/free-course/subscribe"', $this->get(self::HOST, '/courses')->body, 'from the course cards');
        $this->assertSame(404, $this->get(self::HOST, '/courses/no-such-course/subscribe')->status);
    }

    public function testAFreeCourseStartsWhenYouSubscribe(): void
    {
        $this->as('ada@school.test');
        $this->assertStringContainsString('Subscribe and start', $this->get(self::HOST, '/courses/free-course/subscribe')->body);
        $first = (int) $this->db()->fetchValue("SELECT l.id FROM lessons l JOIN courses c ON c.id = l.course_id WHERE c.slug = 'free-course' ORDER BY l.position, l.id LIMIT 1");
        $this->assertRedirectTo('/courses/free-course/lessons/' . $first, $this->post(self::HOST, '/courses/free-course/subscribe'));
        $this->assertTrue($this->enrolled('ada@school.test', 'free-course'));
        $this->assertStringContainsString('You are subscribed', $this->get(self::HOST, '/courses/free-course/subscribe')->body);
        $this->assertRedirectTo('/courses/free-course/subscribe', $this->post(self::HOST, '/courses/free-course/subscribe'), 'twice is harmless');
        $mine = $this->get(self::HOST, '/account/subscriptions')->body;
        $this->assertStringContainsString('href="/courses/free-course"', $mine);
        $this->assertStringContainsString('0 of 2 lessons done', $mine);
    }

    public function testPayingOnlineGoesToPaystack(): void
    {
        $this->as('owner@school.test');
        $this->post(self::HOST, '/school/payments/connect', ['secret' => 'sk_test_abcdefghijklmnopqrstuvwxyz012345', 'currency' => 'NGN']);

        $this->as('ada@school.test');
        $this->assertStringContainsString('Pay ₦15,000 and subscribe', $this->get(self::HOST, '/courses/paid-course/subscribe')->body);
        $go = $this->post(self::HOST, '/courses/paid-course/subscribe');
        $this->assertSame('https://checkout.paystack.com/abc123xyz', $go->header('Location'));
        $this->assertFalse($this->enrolled('ada@school.test', 'paid-course'), 'not before Paystack confirms');
        $this->assertStringContainsString('Online payment of ₦15,000 started', $this->get(self::HOST, '/account/subscriptions')->body);
    }

    public function testPayingTheSchoolDirectlyOpensTheCourseOnceTheSchoolConfirms(): void
    {
        // The school writes how to pay it; a key pasted by mistake is refused.
        $this->as('owner@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/school/payments/instructions', ['instructions' => 'Use sk_live_abcdefghij1234567890'])->status);
        $this->post(self::HOST, '/school/payments/instructions', ['instructions' => "Example Bank\nAccount name: Example School\nAccount number: 0000000000"]);

        $this->as('ada@school.test');
        $this->assertRedirectTo('/courses/paid-course/subscribe#transfer', $this->post(self::HOST, '/courses/paid-course/subscribe'), 'no Paystack: pay the school');
        $page = $this->get(self::HOST, '/courses/paid-course/subscribe')->body;
        $this->assertStringContainsString('Account name: Example School', $page);
        $this->assertStringContainsString('Tell the school I have paid', $page);

        $this->assertRedirectTo('/courses/paid-course/subscribe#transfer', $this->claim(['reference' => '', 'paid_on' => '2999-01-01']));
        $again = $this->get(self::HOST, '/courses/paid-course/subscribe')->body;
        $this->assertStringContainsString('The date cannot be in the future.', $again);
        $this->assertStringContainsString('Enter the reference', $again);
        $this->assertNull($this->claimStatus());

        $this->claim();
        $this->assertSame('pending', $this->claimStatus());
        $this->assertFalse($this->enrolled('ada@school.test', 'paid-course'), 'the learner\'s word opens nothing');
        $this->assertStringContainsString('The school is checking your payment', $this->get(self::HOST, '/courses/paid-course/subscribe')->body);
        $this->assertStringContainsString('The school is checking the ₦15,000', $this->get(self::HOST, '/courses/paid-course')->body);
        $this->assertStringContainsString('Payment being checked', $this->get(self::HOST, '/account/subscriptions')->body);
        $this->claim();
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM payment_claims'), 'one at a time');
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'payment.claim'"), 'the owner is told');
        $this->assertSame(403, $this->post(self::HOST, '/school/payments/claims/1/confirm')->status, 'learners cannot confirm');
        $id = (int) $this->db()->fetchValue('SELECT id FROM payment_claims');

        $this->as('teacher@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/school/payments/claims/' . $id . '/confirm')->status, 'nor teachers');

        $this->as('owner@school.test');
        $school = $this->get(self::HOST, '/school/payments')->body;
        $this->assertStringContainsString('Payments to confirm', $school);
        $this->assertStringContainsString('000013261019120000123456', $school);
        $this->assertRedirectTo('/school/payments#claims', $this->post(self::HOST, '/school/payments/claims/' . $id . '/confirm'));
        $this->assertSame('confirmed', $this->claimStatus());
        $this->assertTrue($this->enrolled('ada@school.test', 'paid-course'));
        $payment = $this->db()->fetchOne('SELECT provider, status, amount_minor, note FROM payments') ?? [];
        $this->assertSame(['manual', 'paid', 1500000], [$payment['provider'] ?? null, $payment['status'] ?? null, (int) ($payment['amount_minor'] ?? 0)]);
        $this->assertStringContainsString('ref 000013261019120000123456', (string) ($payment['note'] ?? ''));
        $this->post(self::HOST, '/school/payments/claims/' . $id . '/confirm');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM payments'), 'confirming twice records once');

        $this->as('ada@school.test');
        $mine = $this->get(self::HOST, '/account/subscriptions')->body;
        $this->assertMatchesRegularExpression('#href="/account/receipts/MAN-\d+-[A-F0-9]+">#', $mine);
        $this->assertStringContainsString('paid to the school', $mine);
    }

    public function testTheSchoolCanSayThePaymentWasNotFound(): void
    {
        $this->as('bo@school.test');
        $this->claim(['amount' => '1,500']);
        $id = (int) $this->db()->fetchValue('SELECT id FROM payment_claims');

        $this->as('owner@school.test');
        $this->assertStringContainsString('Less than the price', $this->get(self::HOST, '/school/payments')->body);
        $this->post(self::HOST, '/school/payments/claims/' . $id . '/reject', ['reason' => '']);
        $this->assertSame('pending', $this->claimStatus(), 'a reason is needed');
        $this->post(self::HOST, '/school/payments/claims/' . $id . '/reject', ['reason' => 'Only ₦1,500 arrived; the course is ₦15,000.']);
        $this->assertSame('rejected', $this->claimStatus());
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM payments'));

        $this->as('bo@school.test');
        $page = $this->get(self::HOST, '/courses/paid-course/subscribe')->body;
        $this->assertStringContainsString('could not confirm your ₦1,500 payment', $page);
        $this->assertStringContainsString('Only ₦1,500 arrived', $page);
        $this->assertStringContainsString('Tell the school I have paid', $page, 'they can send a corrected note');
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'payment.claim_decision'"));

        $this->claim();
        $new = (int) $this->db()->fetchValue('SELECT MAX(id) FROM payment_claims');
        $this->post(self::HOST, '/courses/paid-course/subscribe/transfer/withdraw', ['claim' => (string) $new]);
        $this->assertSame('withdrawn', $this->claimStatus());

        $this->as('ada@school.test');
        $this->assertSame(404, $this->post(self::HOST, '/courses/paid-course/subscribe/transfer/withdraw', ['claim' => (string) $new])->status, 'not someone else\'s');
    }

    public function testCoursesThatTakeApplicationsSendPeopleToApply(): void
    {
        $this->app->get(CourseRepository::class)->updateEnrolment($this->tenant->id, $this->paid->id, 'apply', null);
        $this->assertRedirectTo('/courses/paid-course#apply', $this->get(self::HOST, '/courses/paid-course/subscribe'));
        $this->as('ada@school.test');
        $this->assertRedirectTo('/courses/paid-course#apply', $this->get(self::HOST, '/courses/paid-course/subscribe'));
        $this->assertRedirectTo('/courses/paid-course#apply', $this->post(self::HOST, '/courses/paid-course/subscribe'));
        $this->claim();
        $this->assertNull($this->claimStatus(), 'no paying before a place');
    }
}
