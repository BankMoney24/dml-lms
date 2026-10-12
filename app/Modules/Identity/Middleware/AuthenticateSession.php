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
 * Attaches the signed-in user (if any) for the current tenant. The only
 * thing it blocks: a user holding a one-time password is sent to their
 * account page until they choose their own.
 */
final class AuthenticateSession implements Middleware
{
    private const ALLOWED_WHILE_PASSWORD_PENDING = ['/account', '/account/password', '/logout'];

    public function __construct(
        private readonly AuthService $auth,
        private readonly TenantContext $tenants,
        private readonly View $view,
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
            }
        }

        return $next($request);
    }
}
