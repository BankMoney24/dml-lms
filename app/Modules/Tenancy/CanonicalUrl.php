<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Http\Request;

/**
 * The one address each school's pages should be known by (ADR 0019): its
 * primary verified domain, on the configured public scheme. Canonical links,
 * social previews, structured data and the sitemap are built from this, never
 * from whatever Host header a request happened to carry. Schools without a
 * primary domain fall back to the (already verified) host of the request.
 */
final class CanonicalUrl
{
    /** @var array<int, ?string> */
    private array $primaryHosts = [];

    /** @param string $scheme "https" or "http"; empty means "the scheme of the request" */
    public function __construct(private readonly TenantRepository $tenants, private readonly string $scheme = '')
    {
    }

    public function primaryHost(Tenant $tenant): ?string
    {
        if (!array_key_exists($tenant->id, $this->primaryHosts)) {
            $this->primaryHosts[$tenant->id] = $this->tenants->primaryHost($tenant->id);
        }

        return $this->primaryHosts[$tenant->id];
    }

    public function base(?Tenant $tenant, Request $request): string
    {
        $host = $tenant === null ? null : $this->primaryHost($tenant);
        $scheme = in_array($this->scheme, ['http', 'https'], true) ? $this->scheme : ($request->secure ? 'https' : 'http');
        if ($host === null || $host === $request->host) {
            // On the primary host itself, keep the port it was reached on (a temporary :8088 address keeps working).
            $own = $request->baseUrl();

            return $this->scheme === 'https' && !$request->secure ? (string) preg_replace('#^http://#', 'https://', (string) preg_replace('#:\d+$#', '', $own)) : $own;
        }

        return $scheme . '://' . $host;
    }

    /** Whether this request is on another address than the canonical one (an alias host, or plain HTTP when HTTPS is set). */
    public function isAlias(Tenant $tenant, Request $request): bool
    {
        $host = $this->primaryHost($tenant);
        if ($host === null) {
            return false;
        }

        return $request->host !== $host || ($this->scheme === 'https' && !$request->secure);
    }
}
