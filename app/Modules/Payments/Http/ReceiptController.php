<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Permission;
use App\Modules\Identity\UserRepository;
use App\Modules\Payments\PaymentRepository;

/** A printable receipt for a confirmed payment (ADR 0024). The payer and school managers can open it. */
final class ReceiptController
{
    public function __construct(
        private readonly View $view,
        private readonly PaymentRepository $payments,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
        private readonly ?\App\Modules\Payments\Refunds\RefundRepository $refunds = null,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $payment = $this->payments->findByReference($tenant->id, $request->routeParam('reference'));
        $shown = [\App\Modules\Payments\Payment::PAID, \App\Modules\Payments\Payment::REFUNDED];
        if ($payment === null || !in_array($payment->status, $shown, true) || ($payment->userId !== $me->user->id && !$me->can(Permission::SchoolManage))) {
            throw HttpException::notFound();
        }
        $payer = $this->users->findById($payment->userId);

        return Response::html($this->view->render('payments/receipt', [
            'pageTitle' => 'Receipt ' . $payment->reference,
            'payment' => $payment,
            'course' => $this->courses->findById($tenant->id, $payment->courseId),
            'payerName' => $payer !== null ? $payer->displayName : '',
            'payerEmail' => $payer !== null ? $payer->email : '',
            'refunds' => $this->refunds?->forPayment($tenant->id, $payment->id) ?? [],
        ]));
    }
}
