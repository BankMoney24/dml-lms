<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Resolves the tenant from the verified Host mapping. A client-supplied
 * tenant id is never used to choose or authorise a tenant.
 */
final class ResolveTenant implements Middleware
{
    /** @param list<string> $platformHosts hosts that serve the platform itself, not a tenant */
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantContext $context,
        private readonly array $platformHosts,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->context->set(null);

        if (!in_array($request->host, $this->platformHosts, true)) {
            $tenant = $this->tenants->findByVerifiedHost($request->host);
            if ($tenant === null) {
                throw new HttpException(404, 'This address is not connected to a DML learning space.');
            }
            if (!$tenant->isActive()) {
                throw new HttpException(503, 'This learning space is temporarily unavailable. Please contact your institution.');
            }
            $this->context->set($tenant);
            $request = $request->withAttribute('tenant', $tenant);
        }

        return $next($request);
    }
}
