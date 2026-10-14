<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Mail\MailSettings;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\Role;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Identity\UserRepository;
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
        private readonly AccountLinkService $links,
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactor,
        private readonly MailSettings $mail,
        private readonly ?\App\Modules\Guardians\GuardianRepository $guardians = null,
        private readonly ?\App\Modules\Joining\ProfileRepository $profiles = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $emailing = $this->mail->deliveryAvailable();
        $old = [
            'name' => $request->input('name', '') ?? '',
            'email' => $request->input('email', '') ?? '',
            'role' => $request->input('role', '') ?? '',
        ];
        try {
            $added = $this->members->add($tenant, $me, $old['name'], $old['email'], $old['role'], $request->clientIp, !$emailing);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $old, 422);
        }

        if ($emailing) {
            $user = $this->users->findById($added['user_id']);
            $role = Role::from($added['role']);
            if ($user !== null) {
                $added['existing_account']
                    ? $this->links->sendAddedNotice($tenant, $user, $me, $role, RequestContext::baseUrl($request))
                    : $this->links->sendInvitation($tenant, $user, $me, $role, RequestContext::baseUrl($request));
            }
        }

        // A one-time password (when email is not set up) is shown exactly once, on the next page.
        $this->session->flash(self::NEW_MEMBER_FLASH, json_encode($added + ['emailed' => $emailing], JSON_THROW_ON_ERROR));

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

    public function sendLink(Request $request): Response
    {
        if (!$this->mail->deliveryAvailable()) {
            $this->session->flash('error', 'Email is not set up for this site yet, so links cannot be sent.');

            return Response::redirect('/people');
        }
        try {
            $user = $this->links->sendLinkFor(
                RequestContext::tenant($request),
                RequestContext::user($request),
                $request->routeInt('user'),
                RequestContext::baseUrl($request),
                $request->clientIp,
            );
            $this->session->flash('success', sprintf('Sign-in link emailed to %s.', $user->email));
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/people');
    }

    public function resetTwoStep(Request $request): Response
    {
        try {
            $this->twoFactor->resetFor(RequestContext::tenant($request), RequestContext::user($request), $request->routeInt('user'), $request->clientIp);
            $this->session->flash('success', 'Two-step sign-in reset. They will set it up again next time they sign in.');
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

        $members = $this->memberships->listActive($tenant->id);

        return Response::html($this->view->render('people/index', [
            'pageTitle' => 'People',
            'members' => $members,
            'profiles' => $this->profiles?->many($tenant->id, array_map(static fn (array $m): int => (int) $m['user_id'], $members)) ?? [],
            'roles' => $this->members->assignableRoles($me),
            'meId' => $me->user->id,
            'iAmOwner' => $me->membership?->role === Role::Owner,
            'emailing' => $this->mail->deliveryAvailable(),
            'newMember' => $newMember,
            'guardianCounts' => $this->guardians?->counts($tenant->id) ?? [],
            'find' => mb_substr(trim($request->query('find', '') ?? ''), 0, 120),
            'errors' => $errors,
            'old' => $old + ['name' => '', 'email' => '', 'role' => 'learner'],
        ]), $status);
    }
}
