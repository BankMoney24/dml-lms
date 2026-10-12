<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Clock;
use App\Core\Database\Connection;
use InvalidArgumentException;

final class TenantRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** Only verified domains resolve to a tenant (TEN-002). */
    public function findByVerifiedHost(string $host): ?Tenant
    {
        $row = $this->db->fetchOne(
            'SELECT t.* FROM tenants t
             JOIN tenant_domains d ON d.tenant_id = t.id
             WHERE d.host = ? AND d.verified_at IS NOT NULL',
            [$host],
        );

        return $row === null ? null : Tenant::fromRow($row);
    }

    public function findById(int $id): ?Tenant
    {
        $row = $this->db->fetchOne('SELECT * FROM tenants WHERE id = ?', [$id]);

        return $row === null ? null : Tenant::fromRow($row);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $row = $this->db->fetchOne('SELECT * FROM tenants WHERE slug = ?', [$slug]);

        return $row === null ? null : Tenant::fromRow($row);
    }

    public function create(string $slug, string $name, string $timezone = 'Africa/Lagos', string $locale = 'en-NG'): Tenant
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1) {
            throw new InvalidArgumentException('Tenant slug must be 2–63 lowercase letters, digits or hyphens.');
        }
        $now = $this->clock->sqlNow();
        $id = $this->db->insert(
            'INSERT INTO tenants (slug, name, status, timezone, locale, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$slug, $name, Tenant::STATUS_ACTIVE, $timezone, $locale, $now, $now],
        );

        return new Tenant($id, $slug, $name, Tenant::STATUS_ACTIVE, $timezone, $locale);
    }

    public function addDomain(int $tenantId, string $host, bool $verified, bool $primary = false): void
    {
        $now = $this->clock->sqlNow();
        $this->db->insert(
            'INSERT INTO tenant_domains (tenant_id, host, is_primary, verified_at, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [$tenantId, strtolower($host), $primary ? 1 : 0, $verified ? $now : null, $now],
        );
    }

    public function setStatus(int $tenantId, string $status): void
    {
        if (!in_array($status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
            throw new InvalidArgumentException('Unknown tenant status.');
        }
        $this->db->execute(
            'UPDATE tenants SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $this->clock->sqlNow(), $tenantId],
        );
    }
}
