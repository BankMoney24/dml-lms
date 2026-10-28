<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\Course;
use App\Modules\Identity\Role;
use App\Modules\Payments\PaystackApi;
use App\Modules\Payments\Plans\PaymentPlan;
use App\Modules\Payments\Plans\PaymentPlans;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;
use Tests\Support\FakePaystack;

/** Update 37 (ADR 0037): paying for a course in monthly parts. */
final class PaymentPlansTest extends AppTestCase
{
    private const HOST = 'school.test';

    private FakePaystack $paystack;
    private Tenant $tenant;
    private Course $course;
    private int $readingId;
    private int $quizId;

    protected function setUp(): void
    {
        $this->paystack = new FakePaystack();
        $this->overrides = [PaystackApi::class => $this->paystack];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $made = $this->makeCourse($this->tenant, $teacher, 'networking');
        $this->course = $made['course'];
        $this->readingId = $made['reading']->id;
        $this->quizId = $made['quiz']->id;
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    /** The owner prices the course at ₦60,000, payable in three parts, and (optionally) connects Paystack. */
    private function priceInParts(bool $online = true): void
    {
        $this->as('owner@school.test');
        $this->assertRedirectTo('/teach/courses/' . $this->course->id, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', [
            'currency' => 'NGN', 'price' => '60000', 'instalments' => '3',
        ]));
        if ($online) {
            $this->post(self::HOST, '/school/payments/connect', ['secret' => 'sk_test_abcdefghijklmnopqrstuvwxyz012345', 'currency' => 'NGN']);
        }
    }

    /** Pays whatever checkout was started last, as Paystack would. */
    private function finishCheckout(): void
    {
        $last = end($this->paystack->initialized);
        $this->assertNotFalse($last);
        $this->get(self::HOST, '/payments/paystack/callback?reference=' . $last['reference']);
    }

    /** @return array<string, mixed> */
    private function planRow(): array
    {
        return $this->db()->fetchOne('SELECT * FROM payment_plans ORDER BY id DESC LIMIT 1') ?? [];
    }

    private function enrolled(): bool
    {
        return $this->db()->fetchValue("SELECT 1 FROM enrolments e JOIN users u ON u.id = e.user_id WHERE u.email = 'ada@school.test'") !== null;
    }

    public function testThePriceSplitsIntoPartsWithTheOddAmountFirst(): void
    {
        $this->assertSame(2000000, PaymentPlan::split(6000000, 3, 1));
        $this->assertSame(1666668, PaymentPlan::split(5000000, 3, 1));
        $this->assertSame(1666666, PaymentPlan::split(5000000, 3, 3));
        $this->assertSame(5000000, PaymentPlan::split(5000000, 3, 1) + 2 * PaymentPlan::split(5000000, 3, 2));

        $this->as('owner@school.test');
        $tooSmall = $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['currency' => 'NGN', 'price' => '150', 'instalments' => '4']);
        $this->assertSame(422, $tooSmall->status);
        $this->assertStringContainsString('Each part would be too small', $tooSmall->body);
        $this->assertSame(422, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['currency' => 'NGN', 'price' => '60000', 'instalments' => '9'])->status);
        $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/price', ['currency' => 'NGN', 'price' => '', 'instalments' => '3']);
        $this->assertNull($this->db()->fetchValue('SELECT instalments FROM courses WHERE id = ?', [$this->course->id]), 'a free course has no parts');
    }

    public function testThePartsArePaidOnlineOneAtATime(): void
    {
        $this->priceInParts();
        $this->as('ada@school.test');
        $page = $this->get(self::HOST, '/courses/networking/subscribe')->body;
        $this->assertStringContainsString('In 3 monthly parts: ₦20,000 today', $page);
        $this->assertStringContainsString('or 3 monthly parts', $this->get(self::HOST, '/courses/networking')->body);

        $this->assertSame('https://checkout.paystack.com/abc123xyz', $this->post(self::HOST, '/courses/networking/subscribe', ['plan' => 'parts'])->header('Location'));
        $this->assertSame(2000000, end($this->paystack->initialized)['amount'] ?? null, 'part 1 is a third of the price');
        $this->assertFalse($this->enrolled());
        $this->assertSame('pending', $this->planRow()['status']);

        $this->finishCheckout();
        $this->assertTrue($this->enrolled(), 'part 1 opens the course');
        $this->assertSame('active', $this->planRow()['status']);
        $this->assertNotNull($this->planRow()['started_at']);
        $this->assertSame(200, $this->get(self::HOST, '/courses/networking/lessons/' . $this->readingId)->status);

        $plan = $this->get(self::HOST, '/courses/networking/subscribe')->body;
        $this->assertStringContainsString('Your payment plan', $plan);
        $this->assertStringContainsString('Part 2 (₦20,000) is due on', $plan);
        $this->assertStringContainsString('Pay part 2: ₦20,000', $plan);
        $this->assertStringContainsString('Paying in parts', $this->get(self::HOST, '/account/subscriptions')->body);

        $this->post(self::HOST, '/courses/networking/subscribe/part');
        $this->assertSame(2000000, end($this->paystack->initialized)['amount'] ?? null);
        $this->finishCheckout();
        $this->post(self::HOST, '/courses/networking/subscribe/part');
        $this->finishCheckout();
        $this->assertSame('completed', $this->planRow()['status']);
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM payments WHERE status = 'paid' AND plan_id IS NOT NULL"));
        $this->assertStringContainsString('Every part is paid', $this->get(self::HOST, '/courses/networking/subscribe')->body);
        $this->assertSame(
            'Part 3 of 3 received for ' . $this->course->title,
            $this->db()->fetchValue("SELECT title FROM notifications WHERE kind = 'payment.paid' ORDER BY id DESC LIMIT 1"),
        );
        $this->assertRedirectTo('/courses/networking/subscribe', $this->post(self::HOST, '/courses/networking/subscribe/part'), 'nothing left to pay');
        $this->assertSame(3, count($this->paystack->initialized));
    }

    public function testALatePartPausesTheCourseUntilItIsPaid(): void
    {
        $this->priceInParts();
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/networking/subscribe', ['plan' => 'parts']);
        $this->finishCheckout();
        $planId = (int) $this->planRow()['id'];
        $reminders = $this->app->get(PaymentPlans::class);
        $this->assertSame(['soon' => 0, 'due' => 0, 'paused' => 0], $reminders->sendReminders(), 'nothing is due yet');
        $this->db()->execute('UPDATE payment_plans SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 28 DAY) WHERE id = ?', [$planId]);
        $this->assertSame(1, $reminders->sendReminders()['soon'], 'due in two days');
        $this->db()->execute('UPDATE payment_plans SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 33 DAY) WHERE id = ?', [$planId]);
        $this->assertSame(1, $reminders->sendReminders()['due'], 'three days late');
        $this->assertSame(200, $this->get(self::HOST, '/courses/networking/lessons/' . $this->readingId)->status, 'still open in the grace week');
        $this->assertStringContainsString('Pay it by', $this->get(self::HOST, '/courses/networking/subscribe')->body);

        // Part 2 was due 10 days ago: beyond the 7-day grace, so the course pauses.
        $this->db()->execute('UPDATE payment_plans SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY) WHERE id = ?', [$planId]);
        $this->assertRedirectTo('/courses/networking/subscribe#plan', $this->get(self::HOST, '/courses/networking/lessons/' . $this->readingId));
        $blocked = $this->post(self::HOST, '/courses/networking/lessons/' . $this->quizId . '/attempts', []);
        $this->assertSame(403, $blocked->status);
        $this->assertStringContainsString('This course is paused: part 2 of your payment plan', $blocked->body);
        $this->assertStringContainsString('The course is paused', $this->get(self::HOST, '/courses/networking')->body);
        $this->assertStringContainsString('The lessons open again as soon as it is paid', $this->get(self::HOST, '/courses/networking/subscribe')->body);

        // Reminders: the learner and the school hear once; a second run sends nothing.
        $this->assertSame(1, $reminders->sendReminders()['paused']);
        $this->assertSame(['soon' => 0, 'due' => 0, 'paused' => 0], $reminders->sendReminders());
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'payment.plan_reminder'"));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'payment.plan_paused'"));

        // The school sees it and records part 2 paid at the office: the course opens again.
        $this->as('owner@school.test');
        $list = $this->get(self::HOST, '/school/payments')->body;
        $this->assertStringContainsString('Paused: overdue', $list);
        $this->assertStringContainsString('₦40,000 to come', $list);
        $this->assertStringContainsString('Record part 2', $this->get(self::HOST, '/school/payments/plans/' . $planId)->body);
        $this->assertSame(422, $this->post(self::HOST, '/school/payments/plans/' . $planId . '/record', ['note' => ''])->status);
        $this->assertRedirectTo('/school/payments/plans/' . $planId, $this->post(self::HOST, '/school/payments/plans/' . $planId . '/record', ['note' => 'Cash at the office, receipt 22']));
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT MAX(plan_part) FROM payments WHERE plan_id = ? AND status = 'paid'", [$planId]));

        $this->as('ada@school.test');
        $this->assertSame(200, $this->get(self::HOST, '/courses/networking/lessons/' . $this->readingId)->status);

        // Closing the plan stops the asking; the course stays open.
        $this->db()->execute('UPDATE payment_plans SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 75 DAY) WHERE id = ?', [$planId]);
        $this->assertSame(403, $this->post(self::HOST, '/courses/networking/lessons/' . $this->quizId . '/attempts', [])->status);
        $this->as('owner@school.test');
        $this->assertRedirectTo('/school/payments/plans/' . $planId, $this->post(self::HOST, '/school/payments/plans/' . $planId . '/write-off', ['reason' => 'Scholarship from term 2']));
        $this->assertSame('written_off', $this->planRow()['status']);
        $this->assertStringContainsString('closed the payment plan of', $this->get(self::HOST, '/activity')->body);
        $this->as('ada@school.test');
        $this->assertSame(200, $this->get(self::HOST, '/courses/networking/lessons/' . $this->readingId)->status);
    }

    public function testPartsCanBePaidToTheSchoolDirectly(): void
    {
        $this->priceInParts(false);
        $this->as('ada@school.test');
        $page = $this->get(self::HOST, '/courses/networking/subscribe')->body;
        $this->assertStringContainsString('Part 1 of 3 (₦20,000)', $page);
        $this->post(self::HOST, '/courses/networking/subscribe/transfer', [
            'for' => 'part', 'method' => 'transfer', 'amount' => '20000', 'paid_on' => gmdate('Y-m-d'), 'reference' => 'TRF-001', 'note' => '',
        ]);
        $claim = $this->db()->fetchOne('SELECT * FROM payment_claims ORDER BY id DESC LIMIT 1') ?? [];
        $this->assertSame(1, (int) $claim['plan_part']);

        $this->as('owner@school.test');
        $this->assertStringContainsString('for part 1 of 3', $this->get(self::HOST, '/school/payments')->body);
        $this->post(self::HOST, '/school/payments/claims/' . $claim['id'] . '/confirm');
        $this->assertSame('active', $this->planRow()['status']);
        $this->assertTrue($this->enrolled());

        // The next note is for part 2, without choosing.
        $this->as('ada@school.test');
        $this->assertStringContainsString('Pay the school, then tell them', $this->get(self::HOST, '/courses/networking/subscribe')->body);
        $this->post(self::HOST, '/courses/networking/subscribe/transfer', [
            'method' => 'transfer', 'amount' => '20000', 'paid_on' => gmdate('Y-m-d'), 'reference' => 'TRF-002', 'note' => '',
        ]);
        $second = $this->db()->fetchOne('SELECT * FROM payment_claims ORDER BY id DESC LIMIT 1') ?? [];
        $this->assertSame(2, (int) $second['plan_part']);
        $this->as('owner@school.test');
        $this->post(self::HOST, '/school/payments/claims/' . $second['id'] . '/confirm');
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM payments WHERE plan_id = ? AND status = 'paid'", [$this->planRow()['id']]));

        // Recording a full payment by hand for someone on a plan points to the plan instead.
        $manual = $this->post(self::HOST, '/school/payments/manual', ['email' => 'ada@school.test', 'course_id' => (string) $this->course->id, 'amount' => '20000', 'note' => 'Cash']);
        $this->assertSame(422, $manual->status);
        $this->assertStringContainsString('paying this course in parts', $manual->body);
    }

    public function testOnlyTheSchoolSeesItsPlans(): void
    {
        $this->priceInParts();
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/networking/subscribe', ['plan' => 'parts']);
        $this->finishCheckout();
        $planId = (int) $this->planRow()['id'];
        $this->assertSame(403, $this->get(self::HOST, '/school/payments/plans/' . $planId)->status);
        $this->as('teacher@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/school/payments/plans/' . $planId . '/write-off', ['reason' => 'Because I said so'])->status);

        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'owner@other.test', Role::Owner);
        $this->newBrowser();
        $this->login('other.test', 'owner@other.test', 'correct-horse-battery');
        $this->assertSame(404, $this->get('other.test', '/school/payments/plans/' . $planId)->status);

        $this->as('owner@school.test');
        $this->assertStringContainsString('payment plan', $this->get(self::HOST, '/school/payments/' . (int) $this->db()->fetchValue('SELECT id FROM payments LIMIT 1'))->body);
    }
}
