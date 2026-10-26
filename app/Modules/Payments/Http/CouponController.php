<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Payments\Coupons\Coupons;

/** School → Payments → Discount codes (ADR 0032). */
final class CouponController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Coupons $coupons,
        private readonly CourseRepository $courses,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function store(Request $request): Response
    {
        $input = [];
        foreach (['code', 'kind', 'value', 'course_id', 'max_uses', 'expires_on', 'note'] as $key) {
            $input[$key] = (string) ($request->input($key, '') ?? '');
        }
        try {
            $coupon = $this->coupons->create(RequestContext::tenant($request), RequestContext::user($request), $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Code ' . $coupon->code . ' is ready: ' . $coupon->label() . '. Give it to the people it is for; they type it before they pay.');

        return Response::redirect('/school/payments/codes');
    }

    public function status(Request $request): Response
    {
        $active = $request->input('active', '0') === '1';
        $coupon = $this->coupons->setActive(RequestContext::tenant($request), RequestContext::user($request), $request->routeInt('coupon'), $active, $request->clientIp);
        $this->session->flash('success', $active ? 'Code ' . $coupon->code . ' works again.' : 'Code ' . $coupon->code . ' is switched off. Payments already made with it are not affected.');

        return Response::redirect('/school/payments/codes');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('school/coupons', [
            'pageTitle' => 'Discount codes',
            'coupons' => $this->coupons->all($tenant),
            'paidCourses' => $this->courses->listPaid($tenant->id),
            'errors' => $errors,
            'old' => $old + ['code' => '', 'kind' => 'percent', 'value' => '', 'course_id' => '', 'max_uses' => '', 'expires_on' => '', 'note' => ''],
        ]), $status);
    }
}
