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

        return $this->findById($id) ?? new Tenant($id, $slug, $name, Tenant::STATUS_ACTIVE, $timezone, $locale);
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

    /**
     * Saves what the setup wizard collected.
     *
     * @param array{name: string, kind: ?string, tagline: string, theme: string, contact_email: string,
     *              contact_phone: string, website: string, calendar: ?string, levels: list<string>,
     *              certificates_enabled: bool, public_catalogue: bool} $p
     */
    public function updateProfile(int $tenantId, array $p): void
    {
        $this->db->execute(
            'UPDATE tenants SET name = ?, kind = ?, tagline = ?, theme = ?, contact_email = ?, contact_phone = ?, website = ?,
                    calendar = ?, levels = ?, certificates_enabled = ?, public_catalogue = ?, updated_at = ?
             WHERE id = ?',
            [
                $p['name'], $p['kind'], $p['tagline'], $p['theme'], $p['contact_email'], $p['contact_phone'], $p['website'],
                $p['calendar'], $p['levels'] === [] ? null : json_encode($p['levels'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $p['certificates_enabled'] ? 1 : 0, $p['public_catalogue'] ? 1 : 0, $this->clock->sqlNow(), $tenantId,
            ],
        );
    }

    public function setLogo(int $tenantId, ?string $key, ?string $type): void
    {
        $this->db->execute(
            'UPDATE tenants SET logo_key = ?, logo_type = ?, updated_at = ? WHERE id = ?',
            [$key, $type, $this->clock->sqlNow(), $tenantId],
        );
    }

    /** First completion only; later runs of the wizard keep the original date. */
    public function markSetUp(int $tenantId): void
    {
        $this->db->execute(
            'UPDATE tenants SET setup_completed_at = COALESCE(setup_completed_at, ?) WHERE id = ?',
            [$this->clock->sqlNow(), $tenantId],
        );
    }

    public function slugExists(string $slug): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM tenants WHERE slug = ?', [$slug]) !== null;
    }

    public function hostExists(string $host): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM tenant_domains WHERE host = ?', [strtolower($host)]) !== null;
    }

    /** @return list<array{host: string, primary: bool, verified: bool}> */
    public function domains(int $tenantId): array
    {
        return array_map(static fn (array $r): array => [
            'host' => (string) $r['host'],
            'primary' => (bool) $r['is_primary'],
            'verified' => $r['verified_at'] !== null,
        ], $this->db->fetchAll(
            'SELECT host, is_primary, verified_at FROM tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, host',
            [$tenantId],
        ));
    }

    /**
     * Every school with headline numbers, for platform administrators.
     *
     * @return list<array{tenant: Tenant, host: ?string, members: int, courses: int, learners: int}>
     */
    public function overview(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT t.*,
                    (SELECT d.host FROM tenant_domains d WHERE d.tenant_id = t.id ORDER BY d.is_primary DESC, d.id LIMIT 1) AS primary_host,
                    (SELECT COUNT(*) FROM memberships m WHERE m.tenant_id = t.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM memberships m WHERE m.tenant_id = t.id AND m.status = 'active' AND m.role = 'learner') AS learner_count,
                    (SELECT COUNT(*) FROM courses c WHERE c.tenant_id = t.id) AS course_count
             FROM tenants t ORDER BY t.name, t.id",
        );

        return array_map(static fn (array $r): array => [
            'tenant' => Tenant::fromRow($r),
            'host' => $r['primary_host'] === null ? null : (string) $r['primary_host'],
            'members' => (int) $r['member_count'],
            'courses' => (int) $r['course_count'],
            'learners' => (int) $r['learner_count'],
        ], $rows);
    }
}
