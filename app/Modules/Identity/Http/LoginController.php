<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Middleware\RequireAuthentication;

final class LoginController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly Session $session,
    ) {
    }

    public function show(Request $request): Response
    {
        RequestContext::tenant($request);
        if (RequestContext::optionalUser($request) !== null) {
            return Response::redirect('/');
        }

        return Response::html($this->view->render('auth/login', ['pageTitle' => 'Sign in', 'email' => '', 'error' => null]));
    }

    public function login(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $email = mb_substr(trim($request->input('email', '') ?? ''), 0, 254);
        $password = mb_substr($request->input('password', '') ?? '', 0, 200);

        $result = $this->auth->attempt($tenant, $email, $password, $request->clientIp);
        if (!$result['ok']) {
            // Keep the email (never the password) so the learner only retypes what failed.
            return Response::html($this->view->render('auth/login', [
                'pageTitle' => 'Sign in',
                'email' => $email,
                'error' => $result['message'],
            ]), 422);
        }
        if ($result['second_factor'] ?? false) {
            return Response::redirect('/login/verify');
        }

        $intended = $this->session->get(RequireAuthentication::INTENDED_KEY);
        $this->session->remove(RequireAuthentication::INTENDED_KEY);

        return Response::redirect(is_string($intended) ? $intended : '/');
    }

    public function logout(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::optionalUser($request);
        $this->auth->logout($tenant->id, $user?->user->id, $request->clientIp);
        $this->session->flash('success', 'You have signed out.');

        return Response::redirect('/login');
    }
}
