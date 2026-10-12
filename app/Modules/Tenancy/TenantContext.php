<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Http\HttpException;

/**
 * The tenant resolved for the current request. Set once by ResolveTenant;
 * repositories take the tenant id explicitly rather than reading this
 * implicitly, so every tenant-scoped query shows its scope at the call site.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function require(): Tenant
    {
        if ($this->tenant === null) {
            throw HttpException::notFound();
        }

        return $this->tenant;
    }
}
