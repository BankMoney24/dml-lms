<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Applications\ApplicationRepository;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Payments\PaymentClaim;
use App\Modules\Payments\PaymentRepository;
use App\Modules\Payments\PaymentService;
use App\Modules\Payments\TransferPayments;

/**
 * Subscribing to a course (ADR 0026): one place that takes a person from
 * "I want this course" to the first lesson.
 *
 *   account  →  pay (paid courses only)  →  start
 *
 * Free: subscribing enrols at once. Paid: pay online through Paystack when the
 * school has connected it, or pay the school directly and tell it; either way
 * the course opens only after the payment is confirmed (by Paystack, or by the
 * school checking its bank). Courses that take applications send people to apply.
 */
final class SubscribeController
{
    public const FORM_FLASH = 'transfer_form';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly EnrolmentRepository $enrolments,
        private readonly LearningService $learning,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRows,
        private readonly TransferPayments $transfers,
        private readonly ApplicationRepository $applications,
        private readonly ?\App\Modules\Payments\Coupons\Coupons $coupons = null,
        private readonly ?\App\Core\Security\RateLimiter $limiter = null,
        private readonly ?\App\Modules\Payments\Plans\PaymentPlans $plans = null,
    ) {
    }

    /** GET /courses/{slug}/subscribe */
    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::optionalUser($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }
        $here = '/courses/' . $course->slug . '/subscribe';
        if ($user === null && !$tenant->publicCatalogue && !$course->isOpen()) {
            return Response::redirect('/login?next=' . rawurlencode($here));
        }

        $enrolment = $user === null ? null : $this->learning->findEnrolment($course, $user);
        $state = $this->state($course, $user, $enrolment !== null);
        if ($state === 'apply') {
            return Response::redirect('/courses/' . $course->slug . '#apply');
        }
        $first = null;
        if ($enrolment !== null) {
            $done = $this->learning->completedLessonIds($enrolment);
            foreach ($this->lessons->orderedLessons($tenant->id, $course->id) as $lesson) {
                if (!in_array($lesson->id, $done, true)) {
                    $first = $lesson;
                    break;
                }
            }
        }
        // A discount code typed on this page (ADR 0032): the price shown, and sent to checkout, is the discounted one.
        $code = mb_substr(trim($request->query('code', '') ?? ''), 0, 40);
        $quote = null;
        $codeError = null;
        if ($code !== '' && $state === 'pay' && $user !== null && $this->coupons !== null) {
            if (!$this->codeAttemptAllowed($tenant->id, $user->user->id)) {
                $codeError = 'Too many codes tried. Wait an hour, or ask the school.';
            } else {
                try {
                    $quote = $this->coupons->quote($tenant, $course, $code);
                } catch (ValidationException $e) {
                    $codeError = $e->errors['code'] ?? 'That code cannot be used.';
                }
            }
        }
        // Paying in parts (ADR 0037): the learner's plan, where it stands, and its payments.
        $plan = $user === null || !$course->isPaid() ? null : $this->payments->planFor($tenant, $user->user->id, $course);
        if ($plan !== null && $plan->status === \App\Modules\Payments\Plans\PaymentPlan::PENDING) {
            $plan = null;
        }
        $planNext = $plan?->nextPart();
        $raw = $this->session->pullFlash(self::FORM_FLASH);
        $data = $raw === null ? null : json_decode($raw, true);
        $errors = is_array($data) && is_array($data['errors'] ?? null) ? array_filter($data['errors'], 'is_string') : [];
        $old = is_array($data) && is_array($data['old'] ?? null) ? array_filter($data['old'], 'is_string') : [];

        return Response::html($this->view->render('courses/subscribe', [
            'pageTitle' => 'Subscribe: ' . $course->title,
            'robots' => 'noindex',
            'course' => $course,
            'outline' => $this->lessons->outline($tenant->id, $course->id),
            'state' => $state,
            'nextLesson' => $first,
            'signUpOpen' => $tenant->signUpOpen(),
            'payOnline' => $state === 'pay' && $this->payments->isConnected($tenant),
            'instructions' => $course->isPaid() ? $this->transfers->instructions($tenant) : '',
            'claim' => $user !== null && $course->isPaid() ? $this->transfers->latestFor($tenant, $user, $course) : null,
            'plan' => $plan,
            'planNext' => $planNext,
            'planStanding' => $plan?->standing($this->plans?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            'planPayments' => $plan === null ? [] : $this->paymentRows->forPlan($tenant->id, $plan->id),
            'payParts' => $state === 'pay' && $course->offersInstalments() && $quote === null,
            'onlineOn' => $this->payments->isConnected($tenant),
            'waiting' => $state === 'pay' && $user !== null ? $this->payments->waiting($tenant, $user->user->id, $course) : null,
            'methods' => PaymentClaim::METHODS,
            'codesOn' => $this->coupons !== null,
            'code' => $quote !== null ? $quote['coupon']->code : $code,
            'quote' => $quote,
            'codeError' => $codeError,
            'errors' => $errors,
            'old' => $old + [
                'method' => 'transfer',
                'amount' => $planNext !== null && $plan !== null
                    ? (string) intdiv($plan->partAmount($planNext), 100)
                    : ($course->priceMinor === null ? '' : (string) intdiv($course->priceMinor, 100)),
                'paid_on' => '',
                'reference' => '',
                'note' => '',
                'for' => 'full',
            ],
        ]));
    }

    /** POST /courses/{slug}/subscribe: free → enrol and open the first lesson; paid → pay. */
    public function subscribe(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $course = $this->course($request);
        $here = '/courses/' . $course->slug . '/subscribe';
        if ($this->learning->findEnrolment($course, $user) !== null) {
            return Response::redirect($here);
        }
        if ($this->learning->mustApply($course, $user)) {
            $this->session->flash('error', 'This course takes applications. Apply for a place; the school will add you.');

            return Response::redirect('/courses/' . $course->slug . '#apply');
        }
        $code = mb_substr(trim($request->input('code', '') ?? ''), 0, 40);
        if ($code !== '' && $this->learning->needsPayment($course, $user) && $this->coupons !== null) {
            if (!$this->codeAttemptAllowed($tenant->id, $user->user->id)) {
                $this->session->flash('error', 'Too many codes tried. Wait an hour, or ask the school.');

                return Response::redirect($here);
            }
            try {
                $quote = $this->coupons->quote($tenant, $course, $code);
                if ($quote['price'] === 0) {
                    $this->payments->redeemFree($tenant, $course, $user, $code, $request->clientIp);
                    $first = $this->lessons->orderedLessons($tenant->id, $course->id)[0] ?? null;
                    $this->session->flash('success', 'Code ' . $quote['coupon']->code . ' accepted. You are subscribed to ' . $course->title . '.');

                    return Response::redirect($first === null ? '/courses/' . $course->slug : '/courses/' . $course->slug . '/lessons/' . $first->id);
                }
                if (!$this->payments->isConnected($tenant)) {
                    $this->session->flash('error', 'This code works with online payment, which this school does not take yet. Ask the school about paying the reduced price directly.');

                    return Response::redirect($here . '?code=' . rawurlencode($code) . '#discount');
                }
                $url = $this->payments->startCheckout($tenant, $course, $user, $request->baseUrl(), $request->clientIp, $code);
            } catch (ValidationException $e) {
                $this->session->flash('error', implode(' ', $e->errors));

                return Response::redirect($here . '?code=' . rawurlencode($code) . '#discount');
            }

            return Response::redirectToCheckout($url);
        }
        if ($this->learning->needsPayment($course, $user)) {
            if (!$this->payments->isConnected($tenant)) {
                $this->session->flash('error', 'This school takes payment directly. Pay using the details below, then tell the school.');

                return Response::redirect($here . '#transfer');
            }
            try {
                // "In parts" opens a payment plan and charges its first part (ADR 0037).
                $plan = $request->input('plan') === 'parts' && $course->offersInstalments() ? $this->payments->openPlan($tenant, $course, $user) : null;
                $url = $this->payments->startCheckout($tenant, $course, $user, $request->baseUrl(), $request->clientIp, null, $plan);
            } catch (ValidationException $e) {
                $this->session->flash('error', implode(' ', $e->errors));

                return Response::redirect($here);
            }

            return Response::redirectToCheckout($url);
        }
        $this->learning->enrol($course, $user);
        $first = $this->lessons->orderedLessons($tenant->id, $course->id)[0] ?? null;
        $this->session->flash('success', 'You are subscribed to ' . $course->title . '. ' . ($first === null ? 'Lessons appear here as they are added.' : 'Here is your first lesson.'));

        return Response::redirect($first === null ? '/courses/' . $course->slug : '/courses/' . $course->slug . '/lessons/' . $first->id);
    }

    /** POST /courses/{slug}/subscribe/part: pay the next part of a payment plan online (ADR 0037). */
    public function payPart(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $course = $this->course($request);
        $here = '/courses/' . $course->slug . '/subscribe';
        $plan = $this->payments->planFor($tenant, $user->user->id, $course);
        if ($plan === null || $plan->status !== \App\Modules\Payments\Plans\PaymentPlan::ACTIVE || $plan->nextPart() === null) {
            $this->session->flash('error', 'There is nothing left to pay on this course.');

            return Response::redirect($here);
        }
        if (!$this->payments->isConnected($tenant)) {
            $this->session->flash('error', 'This school takes payment directly. Pay using the details below, then tell the school.');

            return Response::redirect($here . '#transfer');
        }
        try {
            $url = $this->payments->startCheckout($tenant, $course, $user, $request->baseUrl(), $request->clientIp, null, $plan);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect($here . '#plan');
        }

        return Response::redirectToCheckout($url);
    }

    /** Twenty code checks an hour per person, so codes cannot be guessed by trying many. */
    private function codeAttemptAllowed(int $tenantId, int $userId): bool
    {
        return $this->limiter === null || $this->limiter->attempt('coupon:' . $tenantId . ':' . $userId, 20, 3600);
    }

    /** POST /courses/{slug}/subscribe/transfer: "I have paid the school." */
    public function claim(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->course($request);
        $input = [];
        foreach (['method', 'amount', 'paid_on', 'reference', 'note', 'for'] as $key) {
            $input[$key] = (string) ($request->input($key, '') ?? '');
        }
        try {
            $claim = $this->transfers->claim($tenant, $course, RequestContext::user($request), $input, $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash(self::FORM_FLASH, (string) json_encode(['errors' => $e->errors, 'old' => $input]));

            return Response::redirect('/courses/' . $course->slug . '/subscribe#transfer');
        }
        $this->session->flash('success', sprintf('Thank you. The school will check for your %s and open the course for you. You will get a notification.', $claim->amount()));

        return Response::redirect('/courses/' . $course->slug . '/subscribe');
    }

    /** POST /courses/{slug}/subscribe/transfer/withdraw */
    public function withdrawClaim(Request $request): Response
    {
        $course = $this->course($request);
        $id = (string) ($request->input('claim', '') ?? '');
        if (!ctype_digit($id)) {
            throw HttpException::notFound();
        }
        $this->transfers->withdraw(RequestContext::tenant($request), RequestContext::user($request), (int) $id);
        $this->session->flash('success', 'Your payment note is withdrawn. Send a new one when you have the details.');

        return Response::redirect('/courses/' . $course->slug . '/subscribe#transfer');
    }

    /** GET /account/subscriptions: every course this person has, is paying for, or is waiting on. */
    public function mine(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $courses = $this->enrolments->dashboardFor($tenant->id, $user->user->id);
        $now = time();
        $payments = $this->paymentRows->forUser($tenant->id, $user->user->id);
        $paid = array_values(array_filter($payments, static fn (array $r): bool => $r['payment']->isPaid()));
        $checkouts = array_values(array_filter($payments, static fn (array $r): bool => $r['payment']->status === 'pending' && !$r['payment']->looksAbandoned($now)));
        $claims = $this->transfers->forLearner($tenant, $user);
        $applications = array_values(array_filter($this->applications->forUser($tenant->id, $user->user->id), static fn ($a): bool => $a->isActive()));
        $slugs = [];
        foreach ($this->courses->findMany($tenant->id, array_values(array_unique(array_map(static fn (array $r): int => $r['payment']->courseId, $checkouts))) ?: [0]) as $c) {
            $slugs[$c->id] = $c->slug;
        }

        return Response::html($this->view->render('account/subscriptions', [
            'pageTitle' => 'My subscriptions',
            'courses' => $courses,
            'paid' => $paid,
            'checkouts' => $checkouts,
            'checkoutSlugs' => $slugs,
            'pendingClaims' => array_values(array_filter($claims, static fn (PaymentClaim $c): bool => $c->isPending())),
            'rejectedClaims' => array_values(array_filter($claims, static fn (PaymentClaim $c): bool => $c->status === PaymentClaim::REJECTED)),
            'applications' => $applications,
            'plans' => $this->plans?->forLearner($tenant, $user->user->id) ?? [],
            'now' => $this->plans?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]));
    }

    /** account | subscribed | apply | free | pay | cannot */
    private function state(Course $course, ?CurrentUser $user, bool $enrolled): string
    {
        if ($user === null) {
            return $this->learning->isGated($course) ? 'apply' : 'account';
        }
        if ($enrolled) {
            return 'subscribed';
        }
        if (!$user->can(Permission::CourseLearn)) {
            return 'cannot';
        }
        if ($this->learning->mustApply($course, $user)) {
            return 'apply';
        }

        return $this->learning->needsPayment($course, $user) ? 'pay' : 'free';
    }

    private function course(Request $request): Course
    {
        $course = $this->courses->findBySlug(RequestContext::tenant($request)->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }

        return $course;
    }
}
