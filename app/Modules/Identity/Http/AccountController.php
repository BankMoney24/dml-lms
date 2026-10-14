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
use App\Modules\Payments\PaymentRepository;

final class AccountController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountService $accounts,
        private readonly ?PaymentRepository $payments = null,
        private readonly ?\App\Modules\Joining\ProfileRepository $profiles = null,
        private readonly ?\App\Core\Mail\MailSettings $mail = null,
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

    /** From the account menu or page; goes back to the page it came from (same site only). */
    public function updateAppearance(Request $request): Response
    {
        $choice = $request->input('appearance', '') ?? '';
        if ($this->accounts->setAppearance(RequestContext::user($request), $choice)) {
            $this->session->flash('success', 'Appearance set to ' . strtolower(\App\Modules\Identity\User::APPEARANCES[$choice]) . '.');
        }
        $back = $request->input('back', '/account') ?? '/account';

        return Response::redirect(self::isLocalPath($back) ? $back : '/account');
    }

    private static function isLocalPath(string $path): bool
    {
        return preg_match('#^/(?![/\\\\])[^\s]*$#', $path) === 1 && strlen($path) <= 300;
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
            'payments' => $this->payments?->forUser(RequestContext::tenant($request)->id, $me->user->id) ?? [],
            'profile' => $this->profiles?->find(RequestContext::tenant($request)->id, $me->user->id),
            'emailing' => $this->mail?->deliveryAvailable() ?? false,
            'now' => time(),
            'errors' => $errors,
            'old' => $old + ['display_name' => $me->user->displayName],
        ]), $status);
    }
}
