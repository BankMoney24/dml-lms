<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Payments\Money;
use App\Modules\Payments\Payment;
use App\Modules\Payments\PaymentAccountRepository;
use App\Modules\Payments\PaymentRepository;
use App\Modules\Payments\PaymentService;
use App\Modules\Payments\TransferPayments;

/**
 * The school's Payments page: connect Paystack, see who paid, record a
 * payment taken by bank transfer or cash, and download the list.
 */
final class PaymentAdminController
{
    private const FILTERS = ['paid' => Payment::PAID, 'waiting' => Payment::PENDING, 'failed' => Payment::FAILED, 'refunded' => Payment::REFUNDED];
    private const PER_PAGE = 25;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly PaymentService $service,
        private readonly PaymentRepository $payments,
        private readonly PaymentAccountRepository $accounts,
        private readonly CourseRepository $courses,
        private readonly Clock $clock,
        private readonly ?TransferPayments $transfers = null,
        private readonly ?\App\Modules\Payments\Refunds\Refunds $refunds = null,
        private readonly ?\App\Modules\Payments\Refunds\RefundRepository $refundRows = null,
        private readonly ?\App\Modules\Payments\Plans\PaymentPlans $plans = null,
    ) {
    }

    /** GET /school/payments/plans/{plan}: one learner's payment plan (ADR 0037). */
    public function plan(Request $request): Response
    {
        return $this->renderPlan($request, $request->routeInt('plan'));
    }

    /** POST /school/payments/plans/{plan}/record: the next part was paid at the office or by transfer. */
    public function recordPart(Request $request): Response
    {
        $id = $request->routeInt('plan');
        $note = (string) ($request->input('note', '') ?? '');
        try {
            $payment = $this->plans()->recordPart(RequestContext::tenant($request), RequestContext::user($request), $id, $note, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderPlan($request, $id, $e->errors, ['note' => $note], 422);
        }
        $this->session->flash('success', sprintf('Part %d recorded: %s.', (int) $payment->planPart, $payment->amount()));

        return Response::redirect('/school/payments/plans/' . $id);
    }

    /** POST /school/payments/plans/{plan}/write-off: stop asking for the rest. */
    public function writeOff(Request $request): Response
    {
        $id = $request->routeInt('plan');
        $reason = (string) ($request->input('reason', '') ?? '');
        try {
            $this->plans()->writeOff(RequestContext::tenant($request), RequestContext::user($request), $id, $reason, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderPlan($request, $id, $e->errors, ['reason' => $reason, 'writeoff_open' => '1'], 422);
        }
        $this->session->flash('success', 'The plan is closed. Nothing more is asked of the learner, and the course stays open for them.');

        return Response::redirect('/school/payments/plans/' . $id);
    }

    private function plans(): \App\Modules\Payments\Plans\PaymentPlans
    {
        return $this->plans ?? throw new \LogicException('Payment plans are not wired.');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderPlan(Request $request, int $id, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $plan = $this->plans()->find($tenant, $id);

        return Response::html($this->view->render('school/payment_plan', [
            'pageTitle' => 'Payment plan: ' . $plan->learnerName,
            'plan' => $plan,
            'standing' => $plan->standing($this->clock->now()),
            'payments' => $this->payments->forPlan($tenant->id, $plan->id),
            'now' => $this->clock->now()->getTimestamp(),
            'errors' => $errors,
            'old' => $old + ['note' => '', 'reason' => ''],
        ]), $status);
    }

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function connect(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $currency = $request->input('currency', '') ?? '';
        try {
            $mode = $this->service->connect($tenant, RequestContext::user($request), $request->input('secret', '') ?? '', $currency, $request->clientIp);
        } catch (ValidationException $e) {
            // The key is never sent back to the browser, not even to refill the form (PAY-005).
            return $this->render($request, $e->errors, ['currency' => $currency, 'connect_open' => '1'], 422);
        }
        $this->session->flash('success', $mode === 'test'
            ? 'Paystack is connected in test mode. Try a payment with a Paystack test card, then connect your live key.'
            : 'Paystack is connected. Learners can now pay for courses online.');

        return Response::redirect('/school/payments');
    }

    public function disconnect(Request $request): Response
    {
        $this->service->disconnect(RequestContext::tenant($request), RequestContext::user($request), $request->clientIp);
        $this->session->flash('success', 'Paystack is disconnected. Learners cannot pay online until you connect it again.');

        return Response::redirect('/school/payments');
    }

    public function manual(Request $request): Response
    {
        $old = [];
        foreach (['email', 'course_id', 'amount', 'note'] as $key) {
            $old[$key] = $request->input($key, '') ?? '';
        }
        try {
            $payment = $this->service->recordManual(
                RequestContext::tenant($request),
                RequestContext::user($request),
                $old['email'],
                ctype_digit($old['course_id']) ? (int) $old['course_id'] : 0,
                $old['amount'],
                $old['note'],
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $old + ['manual_open' => '1'], 422);
        }
        $this->session->flash('success', sprintf('Payment of %s recorded and the learner is enrolled.', $payment->amount()));

        return Response::redirect('/school/payments');
    }

    /** How learners pay the school directly: shown on every paid course's subscribe page (ADR 0026). */
    public function instructions(Request $request): Response
    {
        $text = $request->input('instructions', '') ?? '';
        try {
            $this->transfers()->saveInstructions(RequestContext::tenant($request), RequestContext::user($request), $text, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, ['instructions' => $text], 422);
        }
        $this->session->flash('success', trim($text) === '' ? 'Payment details removed.' : 'Payment details saved. Learners see them when they subscribe to a paid course.');

        return Response::redirect('/school/payments#claims');
    }

    /** The money arrived: record it and enrol the learner. */
    public function confirmClaim(Request $request): Response
    {
        try {
            $claim = $this->transfers()->confirm(RequestContext::tenant($request), RequestContext::user($request), (int) $request->routeParam('claim'), $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/school/payments#claims');
        }
        $this->session->flash('success', sprintf('%s from %s confirmed. They are enrolled in %s.', $claim->amount(), $claim->learnerName, $claim->courseTitle));

        return Response::redirect('/school/payments#claims');
    }

    public function rejectClaim(Request $request): Response
    {
        try {
            $this->transfers()->reject(RequestContext::tenant($request), RequestContext::user($request), (int) $request->routeParam('claim'), $request->input('reason', '') ?? '', $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/school/payments#claims');
        }
        $this->session->flash('success', 'The learner has been told the payment was not found, with your reason.');

        return Response::redirect('/school/payments#claims');
    }

    private function transfers(): TransferPayments
    {
        return $this->transfers ?? throw new \LogicException('Transfer payments are not wired.');
    }

    /** All payments as a spreadsheet; cells that a spreadsheet would run as a formula are neutralised. */
    public function export(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $now = $this->clock->now()->getTimestamp();
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not build the export.');
        }
        fputcsv($out, ['Date', 'Learner', 'Email', 'Course', 'Amount', 'Currency', 'Status', 'Method', 'Reference', 'Paid at', 'Note'], ',', '"', '');
        // The spreadsheet has what the list shows: the same status, course and words.
        foreach ($this->payments->pageForSchool($tenant->id, $this->filters($request)['where'], 0, 5000)['rows'] as $row) {
            $p = $row['payment'];
            fputcsv($out, array_map(self::cell(...), [
                $p->createdAt,
                $row['learner'],
                $row['email'],
                $row['course'],
                number_format($p->amountMinor / 100, 2, '.', ''),
                $p->currency,
                $p->statusLabel($now),
                $p->methodLabel(),
                $p->reference,
                $p->paidAt ?? '',
                $p->note ?? '',
            ]), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="payments-' . $this->clock->now()->format('Y-m-d') . '.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** POST /school/payments/{payment}/refund: pay back all or part of a payment (ADR 0032). */
    public function refund(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $paymentId = $request->routeInt('payment');
        if ($this->refunds === null) {
            return Response::redirect('/school/payments');
        }
        $input = [
            'refund_amount' => (string) ($request->input('refund_amount', '') ?? ''),
            'refund_reason' => (string) ($request->input('refund_reason', '') ?? ''),
            'withdraw' => $request->input('withdraw', '0') === '1' ? '1' : '0',
        ];
        try {
            $refund = $this->refunds->refund(
                $tenant,
                RequestContext::user($request),
                $paymentId,
                $input['refund_amount'],
                $input['refund_reason'],
                $input['withdraw'] === '1',
                $request->clientIp
            );
        } catch (ValidationException $e) {
            return $this->renderPayment($request, $paymentId, $e->errors, $input, 422);
        }
        $this->session->flash('success', $refund->status === \App\Modules\Payments\Refunds\Refund::PROCESSED
            ? 'Recorded: ' . $refund->amount() . ' paid back. The learner has been told.'
            : 'Paystack is paying back ' . $refund->amount() . ' to the card or account the learner paid with. It usually takes a few working days; this page updates when Paystack confirms it.');

        return Response::redirect('/school/payments/' . $paymentId);
    }

    /** GET /school/payments/{payment}: one payment, what happened to it, and refunds. */
    public function show(Request $request): Response
    {
        return $this->renderPayment($request, $request->routeInt('payment'));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderPayment(Request $request, int $paymentId, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $row = $this->payments->findForSchool($tenant->id, $paymentId) ?? throw \App\Core\Http\HttpException::notFound();
        $payment = $row['payment'];

        return Response::html($this->view->render('school/payment', [
            'pageTitle' => 'Payment ' . $payment->reference,
            'payment' => $payment,
            'learner' => $row['learner'],
            'email' => $row['email'],
            'course' => $row['course'],
            'refunds' => $this->refundRows?->forPayment($tenant->id, $payment->id) ?? [],
            'refundable' => $this->refunds?->refundable($tenant, $payment) ?? 0,
            'events' => $this->payments->events($tenant->id, $payment->id),
            'now' => $this->clock->now()->getTimestamp(),
            'errors' => $errors,
            'old' => $old + ['refund_amount' => '', 'refund_reason' => ''],
        ]), $status);
    }

    /**
     * The history's filters from the query string: ?status=paid&course=12&q=ada.
     *
     * @return array{filter: string, course: ?int, q: string, where: array{status: ?string, course: ?int, q: string}}
     */
    private function filters(Request $request): array
    {
        $filter = (string) ($request->query('status') ?? '');
        $filter = isset(self::FILTERS[$filter]) ? $filter : '';
        $course = (string) ($request->query('course') ?? '');
        $course = ctype_digit($course) && strlen($course) < 10 ? (int) $course : null;
        $q = mb_substr(trim((string) ($request->query('q') ?? '')), 0, 80);

        return ['filter' => $filter, 'course' => $course, 'q' => $q, 'where' => ['status' => $filter === '' ? null : self::FILTERS[$filter], 'course' => $course, 'q' => $q]];
    }

    /** A cell starting with = + - @ or a control character is prefixed with a quote so it is shown, not run. */
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $filters = $this->filters($request);
        $paidCourses = $this->courses->listPaid($tenant->id);
        $account = $this->accounts->find($tenant->id);
        $query = ['status' => $filters['filter'], 'course' => $filters['course'] === null ? '' : (string) $filters['course'], 'q' => $filters['q']];
        $first = $this->payments->pageForSchool($tenant->id, $filters['where'], 0, 1);
        $pager = new \App\Core\View\Pager(\App\Core\View\Pager::pageFrom($request->query('page')), self::PER_PAGE, $first['total'], '/school/payments', $query);
        $rows = $this->payments->pageForSchool($tenant->id, $filters['where'], $pager->offset(), self::PER_PAGE)['rows'];
        $refundRows = $this->refundRows?->forPayments($tenant->id, array_map(static fn (array $r): int => $r['payment']->id, $rows)) ?? [];
        $refundable = [];
        foreach ($rows as $r) {
            $refundable[$r['payment']->id] = $this->refunds?->refundable($tenant, $r['payment']) ?? 0;
        }

        return Response::html($this->view->render('school/payments', [
            'pageTitle' => 'Payments',
            'account' => $account,
            'webhookUrl' => RequestContext::baseUrl($request) . '/webhooks/paystack',
            'callbackUrl' => RequestContext::baseUrl($request) . '/payments/paystack/callback',
            'rows' => $rows,
            'pager' => $pager,
            'q' => $filters['q'],
            'courseFilter' => $filters['course'],
            'paymentCourses' => $this->payments->coursesWithPayments($tenant->id),
            'statusCounts' => $this->payments->statusCounts($tenant->id, $filters['where']),
            'refundRows' => $refundRows,
            'refundable' => $refundable,
            'refundTotals' => $this->refundRows?->totalsSince($tenant->id, $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')) ?? ['count' => 0, 'minor' => 0],
            'filter' => $filters['filter'],
            'totals' => $this->payments->totals($tenant->id, $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')),
            'paidCourses' => $paidCourses,
            'currencies' => array_map(static fn (array $c): string => $c[1] . ' (' . $c[0] . ')', Money::CURRENCIES),
            'now' => $this->clock->now()->getTimestamp(),
            'claims' => $this->transfers?->pending($tenant) ?? [],
            'plans' => $this->plans?->forSchool($tenant) ?? [],
            'planNow' => $this->clock->now(),
            'instructions' => $old['instructions'] ?? ($this->transfers?->instructions($tenant) ?? ''),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
