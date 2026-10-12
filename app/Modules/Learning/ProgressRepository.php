<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Clock;
use App\Core\Database\Connection;

final class ProgressRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function markStarted(int $tenantId, int $enrolmentId, int $lessonId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO lesson_progress (tenant_id, enrolment_id, lesson_id, status, updated_at) VALUES (?, ?, ?, \'started\', ?)',
            [$tenantId, $enrolmentId, $lessonId, $this->clock->sqlNow()],
        );
    }

    /** Idempotent; completion is never downgraded. */
    public function markCompleted(int $tenantId, int $enrolmentId, int $lessonId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO lesson_progress (tenant_id, enrolment_id, lesson_id, status, completed_at, updated_at)
             VALUES (?, ?, ?, \'completed\', ?, ?)
             ON DUPLICATE KEY UPDATE status = \'completed\', completed_at = COALESCE(completed_at, ?), updated_at = ?',
            [$tenantId, $enrolmentId, $lessonId, $now, $now, $now, $now],
        );
    }

    /** @return list<int> */
    public function completedLessonIds(int $tenantId, int $enrolmentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT lesson_id FROM lesson_progress WHERE tenant_id = ? AND enrolment_id = ? AND status = \'completed\'',
            [$tenantId, $enrolmentId],
        );

        return array_map(static fn (array $r): int => (int) $r['lesson_id'], $rows);
    }
}
