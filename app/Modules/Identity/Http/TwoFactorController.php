<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Middleware\RequireAuthentication;
use App\Modules\Identity\TwoFactor\Totp;
use App\Modules\Identity\TwoFactor\TwoFactorService;

/** Sign-in code step, and setting up / managing two-step sign-in on the account page. */
final class TwoFactorController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function showChallenge(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (!$this->auth->hasPendingSecondFactor($tenant)) {
            return Response::redirect('/login');
        }

        return $this->challenge(null);
    }

    public function verifyChallenge(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $result = $this->auth->verifySecondFactor($tenant, $request->input('code', '') ?? '', $request->clientIp);
        if ($result['restart']) {
            $this->session->flash('error', (string) $result['message']);

            return Response::redirect('/login');
        }
        if (!$result['ok']) {
            return $this->challenge($result['message'], 422);
        }
        $intended = $this->session->get(RequireAuthentication::INTENDED_KEY);
        $this->session->remove(RequireAuthentication::INTENDED_KEY);

        return Response::redirect(is_string($intended) ? $intended : '/');
    }

    public function show(Request $request): Response
    {
        return $this->render($request);
    }

    public function enable(Request $request): Response
    {
        $me = RequestContext::user($request);
        try {
            $codes = $this->twoFactor->confirmSetup($me, RequestContext::tenant($request)->id, $request->input('code', '') ?? '');
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, 422);
        }

        return $this->render($request, [], 200, $codes, 'Two-step sign-in is on.');
    }

    public function disable(Request $request): Response
    {
        try {
            $this->twoFactor->disable(
                RequestContext::user($request),
                RequestContext::tenant($request)->id,
                $request->input('password', '') ?? '',
                $request->input('code', '') ?? '',
            );
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, 422);
        }
        $this->session->flash('success', 'Two-step sign-in is off.');

        return Response::redirect('/account/two-step');
    }

    public function regenerateCodes(Request $request): Response
    {
        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes(
                RequestContext::user($request),
                RequestContext::tenant($request)->id,
                $request->input('password', '') ?? '',
            );
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, 422);
        }

        return $this->render($request, [], 200, $codes, 'New recovery codes made. The old ones no longer work.');
    }

    private function challenge(?string $error, int $status = 200): Response
    {
        return Response::html($this->view->render('auth/two_step', [
            'pageTitle' => 'Enter your code',
            'error' => $error,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param list<string>|null     $codes  recovery codes to show once
     */
    private function render(Request $request, array $errors = [], int $status = 200, ?array $codes = null, ?string $notice = null): Response
    {
        $me = RequestContext::user($request);
        $tenant = RequestContext::tenant($request);
        $enabled = $this->twoFactor->isEnabled($me->user->id);
        $secret = $enabled ? null : $this->twoFactor->pendingSecret();

        return Response::html($this->view->render('account/two_step', [
            'pageTitle' => 'Two-step sign-in',
            'enabled' => $enabled,
            'required' => $this->twoFactor->isRequiredFor($me->membership?->role),
            'secretGrouped' => $secret === null ? null : Totp::grouped($secret),
            'otpauth' => $secret === null ? null : Totp::provisioningUri($secret, $me->user->email, $tenant->name),
            'remaining' => $enabled ? $this->twoFactor->unusedRecoveryCodes($me->user->id) : 0,
            'codes' => $codes,
            'notice' => $notice,
            'errors' => $errors,
        ]), $status);
    }
}
