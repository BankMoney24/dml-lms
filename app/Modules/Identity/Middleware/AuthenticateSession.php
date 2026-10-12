<?php

declare(strict_types=1);

namespace App\Modules\Identity\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Identity\AuthService;
use App\Modules\Tenancy\TenantContext;

/**
 * Attaches the signed-in user (if any) for the current tenant. It blocks in
 * two cases only, sending the user to the page that fixes it:
 *   - they hold a one-time password            -> /account (choose a password)
 *   - their role requires two-step sign-in     -> /account/two-step (turn it on)
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
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $tenant = $this->tenants->current();
        $this->view->share('tenant', $tenant);

        if ($tenant !== null) {
            $current = $this->auth->resolve($tenant);
            if ($current !== null) {
                $request = $request->withAttribute('auth', $current);
                $this->view->share('user', $current->user);
                $this->view->share('role', $current->membership?->role);
                $this->view->share('permissions', $current->permissionNames());

                if ($current->user->mustChangePassword && !in_array($request->path, self::ALLOWED_WHILE_PASSWORD_PENDING, true)) {
                    return Response::redirect('/account');
                }
                $role = $current->membership?->role->value;
                if (
                    !$current->user->twoFactorEnabled
                    && $role !== null
                    && in_array($role, $this->twoStepRequiredRoles, true)
                    && !in_array($request->path, self::ALLOWED_WHILE_TWO_STEP_PENDING, true)
                ) {
                    return Response::redirect('/account/two-step');
                }
            }
        }

        return $next($request);
    }
}
