<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Identity\AccountService;

final class AccountController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountService $accounts,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->render($request);
    }

    public function updateName(Request $request): Response
    {
        $name = $request->input('display_name', '') ?? '';
        try {
            $this->accounts->updateDisplayName(RequestContext::user($request), RequestContext::tenant($request)->id, $name);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, ['display_name' => $name], 422);
        }
        $this->session->flash('success', 'Name saved.');

        return Response::redirect('/account');
    }

    public function updatePassword(Request $request): Response
    {
        $me = RequestContext::user($request);
        try {
            $this->accounts->changePassword(
                $me,
                RequestContext::tenant($request)->id,
                $request->input('current_password', '') ?? '',
                $request->input('new_password', '') ?? '',
                $request->input('confirm_password', '') ?? '',
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, [], 422);
        }
        $this->session->flash('success', $me->user->mustChangePassword
            ? 'Password set. Welcome!'
            : 'Password changed. Any other devices signed in to your account have been signed out.');

        return Response::redirect($me->user->mustChangePassword ? '/' : '/account');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $me = RequestContext::user($request);

        return Response::html($this->view->render('account/show', [
            'pageTitle' => 'Your account',
            'me' => $me,
            'errors' => $errors,
            'old' => $old + ['display_name' => $me->user->displayName],
        ]), $status);
    }
}
