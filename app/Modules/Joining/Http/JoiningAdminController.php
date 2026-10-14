<?php

declare(strict_types=1);

namespace App\Modules\Joining\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Permission;
use App\Modules\Joining\JoinRequest;
use App\Modules\Joining\JoinRequestRepository;
use App\Modules\Joining\JoinRequests;
use App\Modules\Joining\RegistrationService;
use App\Modules\Tenancy\TenantRepository;

/** School → Joining: who may sign up, the join code, and requests to teach or follow a child (ADR 0020). */
final class JoiningAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly TenantRepository $tenants,
        private readonly JoinRequests $requests,
        private readonly JoinRequestRepository $repo,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $me->authorise(Permission::MembersManage);

        return Response::html($this->view->render('school/joining', [
            'pageTitle' => 'Joining',
            'schoolTab' => 'joining',
            'school' => $tenant,
            'mayConfigure' => $me->can(Permission::SchoolManage),
            'requests' => $this->repo->queue($tenant->id),
            'joinUrl' => RequestContext::baseUrl($request) . '/join',
        ]));
    }

    public function saveSettings(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $me->authorise(Permission::SchoolManage);
        $mode = $request->input('registration', 'closed') ?? 'closed';
        if (!in_array($mode, ['closed', 'code', 'open'], true)) {
            $mode = 'closed';
        }
        $code = $tenant->joinCode ?? ($mode === 'code' ? RegistrationService::newCode() : null);
        $parents = $request->input('parent_requests') === '1';
        $this->tenants->updateJoining($tenant->id, $mode, $code, $parents);
        $this->audit->record($tenant->id, $me->user->id, 'school.joining_changed', 'tenant', $tenant->id, ['registration' => $mode, 'parent_requests' => $parents ? 1 : 0], $request->clientIp);
        $this->session->flash('success', match ($mode) {
            'open' => 'Saved. Anyone can now create a learner account at /join.',
            'code' => 'Saved. People can join with the code ' . $code . '.',
            default => 'Saved. Only staff can add people now.',
        });

        return Response::redirect('/school/joining');
    }

    public function newCode(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $me->authorise(Permission::SchoolManage);
        $code = RegistrationService::newCode();
        $this->tenants->updateJoining($tenant->id, $tenant->registration, $code, $tenant->parentRequests);
        $this->audit->record($tenant->id, $me->user->id, 'school.join_code_changed', 'tenant', $tenant->id, [], $request->clientIp);
        $this->session->flash('success', 'New join code: ' . $code . '. The old one no longer works.');

        return Response::redirect('/school/joining');
    }

    public function approve(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        try {
            $req = $this->requests->approve($tenant, RequestContext::user($request), $request->routeInt('request'), $request->clientIp);
            $this->session->flash('success', $req->kind === JoinRequest::TEACH ? $req->name . ' can now build courses.' : 'Marked as done.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/school/joining');
    }

    public function decline(Request $request): Response
    {
        $this->requests->decline(RequestContext::tenant($request), RequestContext::user($request), $request->routeInt('request'), $request->clientIp);
        $this->session->flash('success', 'Request declined.');

        return Response::redirect('/school/joining');
    }
}
