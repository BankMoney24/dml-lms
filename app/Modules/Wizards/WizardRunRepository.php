<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Wizard progress kept on the server, so it survives sign-out, closed tabs and lost connections. */
final class WizardRunRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, string $wizard): ?WizardRun
    {
        $row = $this->db->fetchOne(
            'SELECT r.state, r.current_step, r.updated_at, u.display_name
             FROM wizard_runs r JOIN users u ON u.id = r.updated_by
             WHERE r.tenant_id = ? AND r.wizard = ?',
            [$tenantId, $wizard],
        );

        return $row === null ? null : WizardRun::fromJson(
            (string) $row['state'],
            (string) $row['current_step'],
            (string) $row['updated_at'],
            (string) $row['display_name'],
        );
    }

    public function save(int $tenantId, string $wizard, WizardRun $run, int $userId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO wizard_runs (tenant_id, wizard, state, current_step, started_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), current_step = VALUES(current_step),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$tenantId, $wizard, $run->toJson(), $run->current, $userId, $userId, $now, $now],
        );
    }

    public function delete(int $tenantId, string $wizard): void
    {
        $this->db->execute('DELETE FROM wizard_runs WHERE tenant_id = ? AND wizard = ?', [$tenantId, $wizard]);
    }
}
