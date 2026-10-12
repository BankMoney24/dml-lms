<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\MembershipRepository;

/** Owners and admins manage who belongs to the institution. */
final class PeopleController
{
    private const NEW_MEMBER_FLASH = 'new_member';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly MembershipRepository $memberships,
        private readonly MemberService $members,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $old = [
            'name' => $request->input('name', '') ?? '',
            'email' => $request->input('email', '') ?? '',
            'role' => $request->input('role', '') ?? '',
        ];
        try {
            $added = $this->members->add($tenant, RequestContext::user($request), $old['name'], $old['email'], $old['role'], $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $old, 422);
        }

        // The one-time password is shown exactly once, on the next page, then discarded.
        $this->session->flash(self::NEW_MEMBER_FLASH, json_encode($added, JSON_THROW_ON_ERROR));

        return Response::redirect('/people');
    }

    public function updateRole(Request $request): Response
    {
        try {
            $this->members->changeRole(
                RequestContext::tenant($request),
                RequestContext::user($request),
                $request->routeInt('user'),
                $request->input('role', '') ?? '',
                $request->clientIp,
            );
            $this->session->flash('success', 'Role updated.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/people');
    }

    public function remove(Request $request): Response
    {
        try {
            $this->members->remove(RequestContext::tenant($request), RequestContext::user($request), $request->routeInt('user'), $request->clientIp);
            $this->session->flash('success', 'Access removed. They are signed out on their next click.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/people');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $newMember = null;
        $raw = $this->session->pullFlash(self::NEW_MEMBER_FLASH);
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            $newMember = is_array($decoded) ? $decoded : null;
        }

        return Response::html($this->view->render('people/index', [
            'pageTitle' => 'People',
            'members' => $this->memberships->listActive($tenant->id),
            'roles' => $this->members->assignableRoles($me),
            'meId' => $me->user->id,
            'iAmOwner' => $me->membership?->role->value === 'owner',
            'newMember' => $newMember,
            'errors' => $errors,
            'old' => $old + ['name' => '', 'email' => '', 'role' => 'learner'],
        ]), $status);
    }
}
