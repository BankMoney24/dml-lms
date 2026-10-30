<?php

declare(strict_types=1);

namespace App\Modules\Identity\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Identity\AuthService;
use App\Modules\Notifications\NotificationRepository;
use App\Modules\Tenancy\TenantContext;

/**
 * Attaches the signed-in user (if any) for the current tenant. It blocks in
 * two cases only, sending the user to the page that fixes it:
 *   - they hold a one-time password            -> /account (choose a password)
 *   - their role (or platform admin status) requires two-step sign-in -> /account/two-step
 */
final class AuthenticateSession implements Middleware
{
    private const ALLOWED_WHILE_PASSWORD_PENDING = ['/account', '/account/password', '/logout'];
    private const ALLOWED_WHILE_TWO_STEP_PENDING = ['/account', '/account/password', '/account/two-step', '/account/two-step/enable', '/logout'];

    /** @param list<string> $twoStepRequiredRoles role values */
    public function __construct(
        private readonly AuthService $auth,
        private readonly TenantContext $tenants,
        private readonly View $view,
        private readonly array $twoStepRequiredRoles = [],
        private readonly ?NotificationRepository $notifications = null,
        private readonly ?\App\Modules\Platform\NavCounts $navCounts = null,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $tenant = $this->tenants->current();
        $this->view->share('tenant', $tenant);
        $this->view->share('words', $tenant !== null && $tenant->learnerTerm !== 'learner' ? ['learner' => \App\Modules\Tenancy\Tenant::LEARNER_TERMS[$tenant->learnerTerm]] : []);

        if ($tenant !== null) {
            $current = $this->auth->resolve($tenant);
            if ($current !== null) {
                $request = $request->withAttribute('auth', $current);
                $this->view->share('user', $current->user);
                $this->view->share('role', $current->membership?->role);
                $this->view->share('permissions', $current->permissionNames());
                // Pages need the bell and sidebar counts; JSON that pages poll (exam saves, live quizzes) does not.
                if (!str_contains((string) $request->header('accept'), 'application/json')) {
                    $this->view->share('unread', $this->notifications?->unreadCount($tenant->id, $current->user->id) ?? 0);
                    $this->view->share('nav_counts', $this->navCounts?->for($current, $tenant->id) ?? []);
                }

                if ($current->user->mustChangePassword && !in_array($request->path, self::ALLOWED_WHILE_PASSWORD_PENDING, true)) {
                    return Response::redirect('/account');
                }
                $role = $current->membership?->role->value;
                // Platform administrators always need it: they can see every school's operations.
                $required = $current->user->isPlatformAdmin || ($role !== null && in_array($role, $this->twoStepRequiredRoles, true));
                if (
                    !$current->user->twoFactorEnabled
                    && $required
                    && !in_array($request->path, self::ALLOWED_WHILE_TWO_STEP_PENDING, true)
                ) {
                    return Response::redirect('/account/two-step');
                }
            }
        }

        return $next($request);
    }
}
