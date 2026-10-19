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
    private const FILTERS = ['paid' => Payment::PAID, 'waiting' => Payment::PENDING, 'failed' => Payment::FAILED];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly PaymentService $service,
        private readonly PaymentRepository $payments,
        private readonly PaymentAccountRepository $accounts,
        private readonly CourseRepository $courses,
        private readonly Clock $clock,
        private readonly ?TransferPayments $transfers = null,
    ) {
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
        foreach ($this->payments->listForSchool($tenant->id, null, 5000) as $row) {
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
        $filter = (string) ($request->query('status') ?? '');
        $filter = isset(self::FILTERS[$filter]) ? $filter : '';
        $paidCourses = $this->courses->listPaid($tenant->id);
        $account = $this->accounts->find($tenant->id);

        return Response::html($this->view->render('school/payments', [
            'pageTitle' => 'Payments',
            'account' => $account,
            'webhookUrl' => RequestContext::baseUrl($request) . '/webhooks/paystack',
            'callbackUrl' => RequestContext::baseUrl($request) . '/payments/paystack/callback',
            'rows' => $this->payments->listForSchool($tenant->id, $filter === '' ? null : self::FILTERS[$filter]),
            'filter' => $filter,
            'totals' => $this->payments->totals($tenant->id, $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')),
            'paidCourses' => $paidCourses,
            'currencies' => array_map(static fn (array $c): string => $c[1] . ' (' . $c[0] . ')', Money::CURRENCIES),
            'now' => $this->clock->now()->getTimestamp(),
            'claims' => $this->transfers?->pending($tenant) ?? [],
            'instructions' => $old['instructions'] ?? ($this->transfers?->instructions($tenant) ?? ''),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
