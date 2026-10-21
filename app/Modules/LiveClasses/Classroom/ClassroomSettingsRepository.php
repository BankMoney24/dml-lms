<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Classroom;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Security\SecretBox;

/** One classroom set-up per school. The secret is sealed with APP_KEY and write-only. */
final class ClassroomSettingsRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly SecretBox $box)
    {
    }

    public function find(int $tenantId): ?ClassroomSettings
    {
        $row = $this->db->fetchOne(
            'SELECT c.*, COALESCE(u.display_name, \'\') AS updated_by_name FROM classroom_settings c LEFT JOIN users u ON u.id = c.updated_by WHERE c.tenant_id = ?',
            [$tenantId],
        );

        return $row === null ? null : new ClassroomSettings(
            (int) $row['tenant_id'],
            (string) $row['domain'],
            (string) $row['app_id'],
            (bool) $row['low_data'],
            (string) $row['updated_at'],
            (string) $row['updated_by_name'],
        );
    }

    /** Only for signing passes. */
    public function secret(int $tenantId): ?string
    {
        $sealed = $this->db->fetchValue('SELECT secret_sealed FROM classroom_settings WHERE tenant_id = ?', [$tenantId]);

        return is_string($sealed) ? $this->box->decrypt($sealed) : null;
    }

    /** Saves the set-up; a null secret keeps the one already stored. */
    public function save(int $tenantId, string $domain, string $appId, ?string $secret, bool $lowData, int $userId): void
    {
        $now = $this->clock->sqlNow();
        if ($secret === null) {
            $this->db->execute(
                'UPDATE classroom_settings SET domain = ?, app_id = ?, low_data = ?, updated_by = ?, updated_at = ? WHERE tenant_id = ?',
                [$domain, $appId, $lowData ? 1 : 0, $userId, $now, $tenantId],
            );

            return;
        }
        $this->db->execute(
            'INSERT INTO classroom_settings (tenant_id, domain, app_id, secret_sealed, low_data, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE domain = VALUES(domain), app_id = VALUES(app_id), secret_sealed = VALUES(secret_sealed),
                                     low_data = VALUES(low_data), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$tenantId, $domain, $appId, $this->box->encrypt($secret), $lowData ? 1 : 0, $userId, $now],
        );
    }

    public function delete(int $tenantId): void
    {
        $this->db->execute('DELETE FROM classroom_settings WHERE tenant_id = ?', [$tenantId]);
    }
}
