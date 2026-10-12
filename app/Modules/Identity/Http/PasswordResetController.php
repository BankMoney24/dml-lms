<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Mail\MailSettings;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\AccountTokenRepository;

/** "Forgot your password?" and the page behind every emailed reset or invitation link. */
final class PasswordResetController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountLinkService $links,
        private readonly MailSettings $mail,
    ) {
    }

    public function showForgot(Request $request): Response
    {
        RequestContext::tenant($request);

        return Response::html($this->view->render('auth/forgot', [
            'pageTitle' => 'Reset your password',
            'available' => $this->mail->deliveryAvailable(),
            'sent' => false,
            'email' => '',
        ]));
    }

    public function sendForgot(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $email = mb_substr(trim($request->input('email', '') ?? ''), 0, 254);
        if ($this->mail->deliveryAvailable()) {
            $this->links->requestReset($tenant, $email, $request->clientIp, $request->baseUrl());
        }

        // Same answer whether or not the address has an account here.
        return Response::html($this->view->render('auth/forgot', [
            'pageTitle' => 'Check your email',
            'available' => $this->mail->deliveryAvailable(),
            'sent' => true,
            'email' => $email,
        ]));
    }

    public function showReset(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $found = $this->links->inspect($tenant, $request->routeParam('token'));

        return $this->render($found === null ? null : $found['purpose'], $found['user']->email ?? null, []);
    }

    public function reset(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $token = $request->routeParam('token');
        $found = $this->links->inspect($tenant, $token);
        if ($found === null) {
            return $this->render(null, null, [], 410);
        }
        try {
            $ok = $this->links->complete(
                $tenant,
                $token,
                $request->input('new_password', '') ?? '',
                $request->input('confirm_password', '') ?? '',
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            return $this->render($found['purpose'], $found['user']->email, $e->errors, 422);
        }
        if (!$ok) {
            return $this->render(null, null, [], 410);
        }
        $this->session->flash('success', $found['purpose'] === AccountTokenRepository::INVITE
            ? 'Your account is ready. Sign in with your new password.'
            : 'Password changed. Sign in with your new password.');

        return Response::redirect('/login');
    }

    /** @param array<string, string> $errors */
    private function render(?string $purpose, ?string $email, array $errors, int $status = 200): Response
    {
        return Response::html($this->view->render('auth/reset', [
            'pageTitle' => $purpose === AccountTokenRepository::INVITE ? 'Set up your account' : 'Choose a new password',
            'valid' => $purpose !== null,
            'invite' => $purpose === AccountTokenRepository::INVITE,
            'email' => $email,
            'errors' => $errors,
        ]), $purpose === null && $status === 200 ? 410 : $status)
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
