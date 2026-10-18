<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Payments\PaymentService;

/**
 * The learner's side of paying for a course: start a checkout, come back
 * from it, and Paystack's webhook. Access is granted by PaymentService only
 * after Paystack itself confirms the payment.
 */
final class PaymentController
{
    /** References this platform creates: DML-{school}-{16 hex}. Anything else is not ours. */
    private const REFERENCE = '/^DML-\d{1,10}-[A-F0-9]{16}$/';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LearningService $learning,
        private readonly PaymentService $payments,
    ) {
    }

    /** POST /courses/{slug}/pay */
    public function pay(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }
        if ($this->learning->mustApply($course, $user)) {
            $this->session->flash('error', 'This course takes applications. Apply for a place first; you pay once the school offers you one.');

            return Response::redirect('/courses/' . $course->slug . '#apply');
        }
        if (!$this->learning->needsPayment($course, $user)) {
            // Free, already paid, or a course builder: nothing to pay.
            $this->learning->enrol($course, $user);

            return Response::redirect('/courses/' . $course->slug);
        }
        try {
            $url = $this->payments->startCheckout($tenant, $course, $user, $request->baseUrl(), $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/courses/' . $course->slug);
        }

        return Response::redirectToCheckout($url);
    }

    /** GET /payments/paystack/callback?reference=… — where Paystack sends the learner back. */
    public function callback(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $reference = trim((string) ($request->query('reference') ?? $request->query('trxref') ?? ''));
        if (preg_match(self::REFERENCE, $reference) !== 1) {
            throw HttpException::notFound();
        }
        $result = $this->payments->confirmReturn($tenant, $user->user->id, $reference);
        $payment = $result['payment'];
        $course = $payment === null ? null : $this->courses->findById($tenant->id, $payment->courseId);
        if ($payment === null || $course === null) {
            throw HttpException::notFound();
        }
        if ($result['outcome'] === 'paid' && $course->isPublished()) {
            $this->session->flash('success', sprintf('Payment of %s received. You are enrolled, so you can start now.', $payment->amount()));

            return Response::redirect('/courses/' . $course->slug);
        }

        return $this->renderResult($course, $result['outcome'], $payment->amount(), $reference);
    }

    /** POST /webhooks/paystack — no session, no CSRF; the HMAC signature is the authentication. */
    public function webhook(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $accepted = $this->payments->handleWebhook($tenant, $request->rawBody, $request->header('x-paystack-signature'));

        return Response::json(['received' => $accepted], $accepted ? 200 : 401);
    }

    private function renderResult(Course $course, string $outcome, string $amount, string $reference): Response
    {
        return Response::html($this->view->render('payments/result', [
            'pageTitle' => match ($outcome) {
                'failed' => 'Payment not completed',
                'paid' => 'Payment received',
                default => 'Confirming your payment',
            },
            'course' => $course,
            'outcome' => $outcome,
            'amount' => $amount,
            'reference' => $reference,
        ]));
    }
}
