<?php

declare(strict_types=1);

namespace App\Modules\Applications\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Applications\Admissions;
use App\Modules\Applications\Application;
use App\Modules\Applications\ApplicationRepository;
use App\Modules\Identity\Permission;

/** Teach → Applications: offer places in order, or decline (ADR 0025). */
final class ApplicationsAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly ApplicationRepository $applications,
        private readonly Admissions $admissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $show = $request->query('show', 'waiting') ?? 'waiting';
        $show = in_array($show, ['waiting', 'offered', 'decided'], true) ? $show : 'waiting';
        $q = mb_substr(trim((string) ($request->query('q') ?? '')), 0, 80);
        $courses = $this->applications->courses($tenant->id);
        $course = (string) ($request->query('course') ?? '');
        $courseId = ctype_digit($course) && isset($courses[(int) $course]) ? (int) $course : null;
        $list = $this->applications->queue($tenant->id, $show, $courseId, 200, $q);
        $positions = [];
        if ($show === 'waiting') {
            foreach ($list as $a) {
                $positions[$a->id] = $this->applications->position($a);
            }
        }

        return Response::html($this->view->render('teach/applications', [
            'pageTitle' => 'Applications',
            'show' => $show,
            'applications' => $list,
            'positions' => $positions,
            'counts' => $this->applications->counts($tenant->id),
            'q' => $q,
            'courseFilter' => $courseId,
            'appCourses' => $courses,
            'mayCreateAccounts' => RequestContext::user($request)->can(Permission::MembersManage),
        ]));
    }

    public function offer(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        try {
            $result = $this->admissions->offer($tenant, RequestContext::user($request), $request->routeInt('application'), $request->clientIp, RequestContext::baseUrl($request));
            $message = $result['status'] === Application::ENROLLED ? 'Place given: they are enrolled and can start now.' : 'Place offered: they pay on the course page to start.';
            if ($result['account_created']) {
                $message .= $result['one_time_password'] !== null
                    ? ' An account was made for them. This school does not send email yet, so give them this one-time password to sign in: ' . $result['one_time_password']
                    : ' An account was made for them and an invitation email is on its way.';
            }
            $this->session->flash('success', $message);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/teach/applications');
    }

    public function decline(Request $request): Response
    {
        $this->admissions->decline(RequestContext::tenant($request), RequestContext::user($request), $request->routeInt('application'), $request->clientIp);
        $this->session->flash('success', 'Application declined.');

        return Response::redirect('/teach/applications');
    }
}
