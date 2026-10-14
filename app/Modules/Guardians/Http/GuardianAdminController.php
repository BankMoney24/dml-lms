<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Mail\MailSettings;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Guardians\GuardianLink;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Guardians\GuardianService;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;

/** People → a learner → Parents and guardians. */
final class GuardianAdminController
{
    private const NEW_GUARDIAN_FLASH = 'new_guardian';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly GuardianService $service,
        private readonly GuardianRepository $links,
        private readonly UserRepository $users,
        private readonly AccountLinkService $accountLinks,
        private readonly MailSettings $mail,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->render($request);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $learnerId = $request->routeInt('user');
        $old = [
            'name' => $request->input('name', '') ?? '',
            'email' => $request->input('email', '') ?? '',
            'relationship' => $request->input('relationship', '') ?? '',
        ];
        $emailing = $this->mail->deliveryAvailable();
        try {
            $result = $this->service->link($tenant, $me, $learnerId, $old['name'], $old['email'], $old['relationship'], $this->permissions($request), !$emailing, $request->clientIp);
        } catch (ValidationException $e) {
            $old += [
                'progress' => $request->input('progress') ?? '',
                'results' => $request->input('results') ?? '',
                'notices' => $request->input('notices') ?? '',
            ];

            return $this->render($request, $e->errors, $old, 422);
        }
        $added = $result['added'];
        if ($added !== null && $emailing) {
            $user = $this->users->findById($added['user_id']);
            if ($user !== null) {
                $added['existing_account']
                    ? $this->accountLinks->sendAddedNotice($tenant, $user, $me, Role::Guardian, RequestContext::baseUrl($request))
                    : $this->accountLinks->sendInvitation($tenant, $user, $me, Role::Guardian, RequestContext::baseUrl($request));
            }
        }
        $this->session->flash(self::NEW_GUARDIAN_FLASH, json_encode([
            'name' => $result['guardian_name'],
            'added' => $added !== null,
            'emailed' => $emailing,
            'email' => $added['email'] ?? '',
            'one_time_password' => $added['one_time_password'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return Response::redirect('/people/' . $learnerId . '/guardians');
    }

    /** @return array{progress: bool, results: bool, notices: bool} */
    private function permissions(Request $request): array
    {
        return [
            'progress' => $request->input('progress') === '1',
            'results' => $request->input('results') === '1',
            'notices' => $request->input('notices') === '1',
        ];
    }

    public function update(Request $request): Response
    {
        $learnerId = $request->routeInt('user');
        $this->service->setPermissions(RequestContext::tenant($request), RequestContext::user($request), $learnerId, $request->routeInt('link'), $this->permissions($request), $request->clientIp);
        $this->session->flash('success', 'What they can see is saved.');

        return Response::redirect('/people/' . $learnerId . '/guardians');
    }

    public function revoke(Request $request): Response
    {
        $learnerId = $request->routeInt('user');
        $this->service->revoke(RequestContext::tenant($request), RequestContext::user($request), $learnerId, $request->routeInt('link'), $request->clientIp);
        $this->session->flash('success', 'Unlinked. They no longer see this learner.');

        return Response::redirect('/people/' . $learnerId . '/guardians');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $learner = $this->service->learnerOf($tenant, $request->routeInt('user'));
        $flash = $this->session->pullFlash(self::NEW_GUARDIAN_FLASH);
        $justLinked = $flash === null ? null : json_decode($flash, true);

        return Response::html($this->view->render('guardians/manage', [
            'pageTitle' => 'Parents and guardians of ' . $learner['name'],
            'learner' => $learner,
            'links' => $this->links->guardiansOf($tenant->id, $learner['id']),
            'relationships' => GuardianLink::RELATIONSHIPS,
            'justLinked' => is_array($justLinked) ? $justLinked : null,
            'emailing' => $this->mail->deliveryAvailable(),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
