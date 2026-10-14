<?php

declare(strict_types=1);

namespace App\Modules\Joining\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Mail\MailSettings;
use App\Core\Security\RateLimiter;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Guardians\GuardianLink;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\Permission;
use App\Modules\Joining\JoinRequest;
use App\Modules\Joining\JoinRequestRepository;
use App\Modules\Joining\JoinRequests;
use App\Modules\Joining\ProfileRepository;
use App\Modules\Joining\RegistrationService;

/**
 * The ways in (ADR 0020): create a learner account (/join), a parent asking to
 * follow their child (/join/parent), a member asking to teach (/join/teach),
 * and confirming an email address.
 */
final class JoinController
{
    /** A field real people never see; anything typed in it is a bot. */
    public const HONEYPOT = 'website';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly RegistrationService $registration,
        private readonly JoinRequests $requests,
        private readonly JoinRequestRepository $requestRepo,
        private readonly ProfileRepository $profiles,
        private readonly AccountLinkService $links,
        private readonly MailSettings $mail,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        if (RequestContext::optionalUser($request) !== null) {
            return Response::redirect('/');
        }

        return $this->joinPage($request);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (($request->input(self::HONEYPOT, '') ?? '') !== '') {
            return Response::redirect('/join');
        }
        $input = [
            'name' => $request->input('name', '') ?? '',
            'email' => $request->input('email', '') ?? '',
            'password' => $request->input('password', '') ?? '',
            'code' => $request->input('code', '') ?? '',
            'terms' => $request->input('terms') === '1',
            'age' => $request->input('age') === '1',
        ];
        try {
            $result = $this->registration->register($tenant, $input, $request->clientIp, RequestContext::baseUrl($request));
        } catch (ValidationException $e) {
            return $this->joinPage($request, $e->errors, ['name' => $input['name'], 'email' => $input['email'], 'code' => $input['code']], 422);
        }
        if ($result['second_factor']) {
            $this->session->flash('success', 'You have joined ' . $tenant->name . '. Sign in with your password and authenticator code to continue.');

            return Response::redirect('/login');
        }
        $this->session->flash('success', 'Welcome to ' . $tenant->name . '!' . ($result['confirmation_sent'] ? ' We sent a link to ' . $result['user']->email . ' to confirm your email.' : ''));

        return Response::redirect('/setup/welcome');
    }

    public function parent(Request $request): Response
    {
        return $this->parentPage($request);
    }

    public function storeParent(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (($request->input(self::HONEYPOT, '') ?? '') !== '') {
            return Response::redirect('/join/parent?sent=1');
        }
        $input = [
            'name' => $request->input('name', '') ?? '',
            'email' => $request->input('email', '') ?? '',
            'phone' => $request->input('phone', '') ?? '',
            'child' => $request->input('child', '') ?? '',
            'relationship' => $request->input('relationship', '') ?? '',
            'message' => $request->input('message', '') ?? '',
            'agree' => $request->input('agree') === '1',
        ];
        try {
            $this->requests->requestGuardianAccess($tenant, $input, $request->clientIp);
        } catch (ValidationException $e) {
            $old = $input;
            unset($old['agree']);

            return $this->parentPage($request, $e->errors, array_map('strval', $old), 422);
        }

        return Response::redirect('/join/parent?sent=1');
    }

    public function teach(Request $request): Response
    {
        return $this->teachPage($request);
    }

    public function storeTeach(Request $request): Response
    {
        $input = [
            'subjects' => $request->input('subjects', '') ?? '',
            'experience' => $request->input('experience', '') ?? '',
            'link' => $request->input('link', '') ?? '',
        ];
        try {
            $this->requests->applyToTeach(RequestContext::tenant($request), RequestContext::user($request), $input, $this->mail->deliveryAvailable(), $request->clientIp);
        } catch (ValidationException $e) {
            return $this->teachPage($request, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Request sent. The school will let you know here.');

        return Response::redirect('/join/teach');
    }

    /** The link in the confirmation email. Works signed in or not. */
    public function confirm(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $ok = $this->links->confirmEmail($tenant, $request->routeParam('token'), $request->clientIp);
        $this->session->flash($ok ? 'success' : 'error', $ok
            ? 'Email confirmed. Thank you.'
            : 'That confirmation link has expired or was already used. Sign in and send a new one from your account page.');

        return Response::redirect(RequestContext::optionalUser($request) !== null ? '/account' : '/login');
    }

    public function resendConfirmation(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        if ($me->user->emailVerified) {
            $this->session->flash('success', 'Your email is already confirmed.');
        } elseif (!$this->mail->deliveryAvailable()) {
            $this->session->flash('error', 'This school does not send email yet, so there is nothing to confirm for now.');
        } elseif (!$this->limiter->attempt('confirm-resend|' . $tenant->id . '|' . $me->user->id, 3, 3600)) {
            $this->session->flash('error', 'We have sent a few links already. Check your spam folder, or try again in an hour.');
        } else {
            $this->links->sendEmailConfirmation($tenant, $me->user, RequestContext::baseUrl($request));
            $this->session->flash('success', 'A new link is on its way to ' . $me->user->email . '.');
        }

        return Response::redirect('/account');
    }

    /** "Not now" on the home page's getting-started card. */
    public function skipWelcome(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $this->profiles->dismissOnboarding($tenant->id, RequestContext::user($request)->user->id);

        return Response::redirect('/');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function joinPage(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('join/index', [
            'pageTitle' => 'Join ' . $tenant->name,
            'school' => $tenant,
            'open' => $tenant->signUpOpen(),
            'emailing' => $this->mail->deliveryAvailable(),
            'errors' => $errors,
            'old' => $old + ['name' => '', 'email' => '', 'code' => $request->query('code', '') ?? ''],
            'indexable' => $tenant->signUpOpen() && $tenant->registration === 'open',
            'metaDescription' => sprintf('Create your free learner account at %s: courses, hands-on labs and certificates.', $tenant->name),
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function parentPage(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        if (!$tenant->parentRequests) {
            throw HttpException::notFound();
        }

        return Response::html($this->view->render('join/parent', [
            'pageTitle' => 'Parents and guardians',
            'school' => $tenant,
            'sent' => $request->query('sent') === '1' && $errors === [],
            'relationships' => GuardianLink::RELATIONSHIPS,
            'errors' => $errors,
            'old' => $old + ['name' => '', 'email' => '', 'phone' => '', 'child' => '', 'relationship' => '', 'message' => ''],
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function teachPage(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);

        return Response::html($this->view->render('join/teach', [
            'pageTitle' => 'Teach at ' . $tenant->name,
            'school' => $tenant,
            'alreadyTeaches' => $me->can(Permission::CourseManage),
            'latest' => $this->requestRepo->pendingFor($tenant->id, $me->user->id, JoinRequest::TEACH),
            'needsConfirmation' => $this->mail->deliveryAvailable() && !$me->user->emailVerified,
            'fromWelcome' => $request->query('from') === 'welcome',
            'errors' => $errors,
            'old' => $old + ['subjects' => '', 'experience' => '', 'link' => ''],
        ]), $status);
    }
}
