<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Route middleware: the page only exists on a tenant host. */
final class RequireTenant implements Middleware
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->tenants->require();

        return $next($request);
    }
}
