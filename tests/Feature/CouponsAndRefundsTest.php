<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Payments\PaystackApi;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;
use Tests\Support\FakePaystack;

/** Discount codes and refunds (ADR 0032). */
final class CouponsAndRefundsTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const KEY = 'sk_test_abcdefghijklmnopqrstuvwxyz012345';

    private FakePaystack $paystack;
    private Tenant $tenant;
    private Course $course;

    protected function setUp(): void
    {
        $this->paystack = new FakePaystack();
        $this->overrides = [PaystackApi::class => $this->paystack];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'other@school.test', Role::Learner);
        $course = $this->makeCourse($this->tenant, $teacher, 'networking')['course'];
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $course->id, 1500000, 'NGN');
        $this->course = $this->app->get(CourseRepository::class)->findById($this->tenant->id, $course->id) ?? $course;
        $this->as('admin@school.test');
        $this->post(self::HOST, '/school/payments/connect', ['secret' => self::KEY, 'currency' => 'NGN']);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    /** @param array<string, string> $extra */
    private function makeCode(string $code, string $kind, string $value, array $extra = []): Response
    {
        $this->as('admin@school.test');

        return $this->post(self::HOST, '/school/payments/codes', ['code' => $code, 'kind' => $kind, 'value' => $value] + $extra);
    }

    private function enrolled(string $email = 'learner@school.test'): bool
    {
        return $this->db()->fetchValue(
            "SELECT 1 FROM enrolments e JOIN users u ON u.id = e.user_id WHERE u.email = ? AND e.status <> 'withdrawn'",
            [$email],
        ) !== null;
    }

    /** @param array<string, mixed> $data */
    private function webhook(string $event, array $data): Response
    {
        $body = (string) json_encode(['event' => $event, 'data' => $data]);

        return $this->send(new Request('POST', '/webhooks/paystack', self::HOST, headers: [
            'content-type' => 'application/json', 'x-paystack-signature' => hash_hmac('sha512', $body, self::KEY),
        ], rawBody: $body));
    }

    public function testAPercentageCodeLowersWhatPaystackIsAskedToCharge(): void
    {
        $this->assertRedirectTo('/school/payments/codes', $this->makeCode('early-bird', 'percent', '20', ['max_uses' => '5']));
        $list = $this->get(self::HOST, '/school/payments/codes')->body;
        $this->assertStringContainsString('EARLY-BIRD', $list);
        $this->assertStringContainsString('0 of 5', $list);

        $this->as('learner@school.test');
        $page = $this->get(self::HOST, '/courses/networking/subscribe?code=early-bird')->body;
        $this->assertStringContainsString('You pay <strong>₦12,000</strong>', $page);
        $this->assertStringContainsString('name="code" value="EARLY-BIRD"', $page);

        $response = $this->post(self::HOST, '/courses/networking/subscribe', ['code' => 'early-bird']);
        $this->assertSame('https://checkout.paystack.com/abc123xyz', $response->header('Location'));
        $this->assertSame(1200000, $this->paystack->initialized[0]['amount']);
        $row = $this->db()->fetchOne('SELECT amount_minor, list_price_minor, coupon_id FROM payments') ?? [];
        $this->assertSame([1200000, 1500000], [(int) ($row['amount_minor'] ?? 0), (int) ($row['list_price_minor'] ?? 0)]);
        $this->assertNotNull($row['coupon_id'] ?? null);

        // Paystack confirms the discounted amount: accepted, and the code counts one use.
        $reference = (string) $this->db()->fetchValue('SELECT reference FROM payments');
        $this->get(self::HOST, '/payments/paystack/callback?reference=' . $reference);
        $this->assertTrue($this->enrolled());
        $this->as('admin@school.test');
        $this->assertStringContainsString('1 of 5', $this->get(self::HOST, '/school/payments/codes')->body);
    }

    public function testAFullCodeEnrolsWithoutPaystackAndRespectsItsUses(): void
    {
        $this->makeCode('SCHOLAR', 'percent', '100', ['max_uses' => '1', 'course_id' => (string) $this->course->id]);

        $this->as('learner@school.test');
        $this->assertStringContainsString('The course is free for you.', $this->get(self::HOST, '/courses/networking/subscribe?code=scholar')->body);
        $this->post(self::HOST, '/courses/networking/subscribe', ['code' => 'scholar']);
        $this->assertTrue($this->enrolled());
        $this->assertSame([], $this->paystack->initialized);
        $this->assertSame('coupon', $this->db()->fetchValue('SELECT provider FROM payments'));

        $this->as('other@school.test');
        $this->assertStringContainsString('used as many times as the school allows', $this->get(self::HOST, '/courses/networking/subscribe?code=SCHOLAR')->body);
        $this->post(self::HOST, '/courses/networking/subscribe', ['code' => 'SCHOLAR']);
        $this->assertFalse($this->enrolled('other@school.test'));
    }

    public function testCodesAreCheckedAndCanBeSwitchedOff(): void
    {
        $bad = $this->makeCode('x', 'percent', '120', ['expires_on' => '2001-01-01']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Use 3 to 32 letters', $bad->body);
        $this->assertStringContainsString('percentage from 1 to 100', $bad->body);
        $this->assertStringContainsString('cannot be in the past', $bad->body);

        $this->makeCode('STAFF', 'amount', '5000');
        $this->assertSame(422, $this->makeCode('staff', 'percent', '10')->status, 'codes are unique without case');
        $id = (int) $this->db()->fetchValue("SELECT id FROM coupons WHERE code = 'STAFF'");
        $this->post(self::HOST, '/school/payments/codes/' . $id . '/status', ['active' => '0']);

        $this->as('learner@school.test');
        $this->assertStringContainsString('switched off by the school', $this->get(self::HOST, '/courses/networking/subscribe?code=staff')->body);
        $this->assertStringContainsString('was not found', $this->get(self::HOST, '/courses/networking/subscribe?code=NOPE')->body);
        $this->assertSame(403, $this->post(self::HOST, '/school/payments/codes', ['code' => 'MINE', 'kind' => 'percent', 'value' => '100'])->status);
    }

    public function testRefundThroughPaystackWithdrawsTheLearnerAndSettlesByWebhook(): void
    {
        $this->as('learner@school.test');
        $this->post(self::HOST, '/courses/networking/pay');
        $reference = (string) $this->db()->fetchValue('SELECT reference FROM payments');
        $this->get(self::HOST, '/payments/paystack/callback?reference=' . $reference);
        $this->assertTrue($this->enrolled());
        $paymentId = (int) $this->db()->fetchValue('SELECT id FROM payments');

        $this->as('admin@school.test');
        $tooMuch = $this->post(self::HOST, '/school/payments/' . $paymentId . '/refund', ['refund_amount' => '20000', 'refund_reason' => 'Course cancelled', 'withdraw' => '1']);
        $this->assertSame(422, $tooMuch->status);
        $this->assertStringContainsString('up to ₦15,000', $tooMuch->body);

        $this->assertRedirectTo('/school/payments/' . $paymentId, $this->post(self::HOST, '/school/payments/' . $paymentId . '/refund', [
            'refund_amount' => '', 'refund_reason' => 'Course cancelled', 'withdraw' => '1',
        ]));
        $this->assertSame([['reference' => $reference, 'amount' => 1500000, 'reason' => 'Course cancelled']], $this->paystack->refunds);
        $this->assertSame('refunded', $this->db()->fetchValue('SELECT status FROM payments'));
        $this->assertSame('pending', $this->db()->fetchValue('SELECT status FROM payment_refunds'));
        $this->assertFalse($this->enrolled(), 'taken off the course');
        $this->assertStringContainsString('Being paid back: ₦15,000, Course cancelled', $this->get(self::HOST, '/school/payments')->body);

        // Paystack confirms; a repeat of the same webhook changes nothing.
        $this->assertSame(200, $this->webhook('refund.processed', ['status' => 'processed', 'transaction_reference' => $reference, 'amount' => 1500000])->status);
        $this->webhook('refund.processed', ['status' => 'processed', 'transaction_reference' => $reference, 'amount' => 1500000]);
        $this->assertSame('processed', $this->db()->fetchValue('SELECT status FROM payment_refunds'));

        // The learner sees the refund on the receipt and must pay again to return.
        $this->as('learner@school.test');
        $receipt = $this->get(self::HOST, '/account/receipts/' . $reference)->body;
        $this->assertStringContainsString('Refunded', $receipt);
        $this->assertStringContainsString('Course cancelled', $receipt);
        $lesson = (int) $this->db()->fetchValue('SELECT MIN(id) FROM lessons');
        $this->assertRedirectTo('/courses/networking#start', $this->get(self::HOST, '/courses/networking/lessons/' . $lesson), 'lessons are closed again');
        $this->assertStringContainsString('Subscribe for ₦15,000', $this->get(self::HOST, '/courses/networking')->body);
    }

    public function testPartRefundOfAHandRecordedPaymentKeepsAccess(): void
    {
        $this->as('admin@school.test');
        $this->post(self::HOST, '/school/payments/manual', ['email' => 'learner@school.test', 'course_id' => (string) $this->course->id, 'amount' => '15000', 'note' => 'Cash at the office']);
        $paymentId = (int) $this->db()->fetchValue('SELECT id FROM payments');
        $this->post(self::HOST, '/school/payments/' . $paymentId . '/refund', ['refund_amount' => '5000', 'refund_reason' => 'Paid too much']);

        $this->assertSame([], $this->paystack->refunds, 'money paid by hand is paid back by hand');
        $this->assertSame('processed', $this->db()->fetchValue('SELECT status FROM payment_refunds'));
        $this->assertSame('paid', $this->db()->fetchValue('SELECT status FROM payments'));
        $this->assertTrue($this->enrolled());
        $detail = $this->get(self::HOST, '/school/payments/' . $paymentId)->body;
        $this->assertStringContainsString('Up to ₦10,000', $detail);
        $this->assertStringContainsString('Paid too much', $detail);
    }

    public function testFailedRefundWebhookMakesThePaymentCountAsPaidAgain(): void
    {
        $this->as('learner@school.test');
        $this->post(self::HOST, '/courses/networking/pay');
        $reference = (string) $this->db()->fetchValue('SELECT reference FROM payments');
        $this->get(self::HOST, '/payments/paystack/callback?reference=' . $reference);
        $paymentId = (int) $this->db()->fetchValue('SELECT id FROM payments');
        $this->as('admin@school.test');
        $this->post(self::HOST, '/school/payments/' . $paymentId . '/refund', ['refund_reason' => 'Paid twice']);

        $this->webhook('refund.failed', ['status' => 'failed', 'transaction_reference' => $reference, 'amount' => 1500000]);
        $this->assertSame('failed', $this->db()->fetchValue('SELECT status FROM payment_refunds'));
        $this->assertSame('paid', $this->db()->fetchValue('SELECT status FROM payments'));
    }
}
