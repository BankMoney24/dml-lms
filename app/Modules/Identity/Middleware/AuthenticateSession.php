<?php

declare(strict_types=1);

namespace App\Modules\Identity\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Identity\AuthService;
use App\Modules\Tenancy\TenantContext;

/** Attaches the signed-in user (if any) for the current tenant. Never blocks by itself. */
final class AuthenticateSession implements Middleware
{
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
            }
        }

        return $next($request);
    }
}
