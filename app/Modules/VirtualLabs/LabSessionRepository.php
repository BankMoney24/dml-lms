<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

use App\Core\Clock;
use App\Core\Database\Connection;

final class LabSessionRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @param array{tenant_id: int, user_id: int, course_id: int, lesson_id: int, enrolment_id: int, lab_key: string, lab_version: int, seed: string, expires_at: string} $s */
    public function create(array $s): int
    {
        return $this->db->insert(
            'INSERT INTO lab_sessions (tenant_id, user_id, course_id, lesson_id, enrolment_id, lab_key, lab_version, seed, status, started_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $s['tenant_id'], $s['user_id'], $s['course_id'], $s['lesson_id'], $s['enrolment_id'],
                $s['lab_key'], $s['lab_version'], $s['seed'], LabSession::OPEN, $this->clock->sqlNow(), $s['expires_at'],
            ],
        );
    }

    public function find(int $tenantId, int $id): ?LabSession
    {
        $row = $this->db->fetchOne('SELECT * FROM lab_sessions WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $row === null ? null : LabSession::fromRow($row);
    }

    /** Locks the row for the duration of the caller's transaction, so one submission wins. */
    public function lock(int $tenantId, int $id): ?LabSession
    {
        $row = $this->db->fetchOne('SELECT * FROM lab_sessions WHERE tenant_id = ? AND id = ? FOR UPDATE', [$tenantId, $id]);

        return $row === null ? null : LabSession::fromRow($row);
    }

    public function openFor(int $tenantId, int $enrolmentId, int $lessonId): ?LabSession
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM lab_sessions WHERE tenant_id = ? AND enrolment_id = ? AND lesson_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$tenantId, $enrolmentId, $lessonId, LabSession::OPEN],
        );

        return $row === null ? null : LabSession::fromRow($row);
    }

    /** @return list<LabSession> newest first */
    public function history(int $tenantId, int $enrolmentId, int $lessonId, int $limit = 10): array
    {
        return array_map([LabSession::class, 'fromRow'], $this->db->fetchAll(
            'SELECT * FROM lab_sessions WHERE tenant_id = ? AND enrolment_id = ? AND lesson_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$tenantId, $enrolmentId, $lessonId],
        ));
    }

    public function closeOpen(int $tenantId, int $enrolmentId, int $lessonId): void
    {
        $this->db->execute(
            'UPDATE lab_sessions SET status = ?, ended_at = ? WHERE tenant_id = ? AND enrolment_id = ? AND lesson_id = ? AND status = ?',
            [LabSession::ENDED, $this->clock->sqlNow(), $tenantId, $enrolmentId, $lessonId, LabSession::OPEN],
        );
    }

    public function launchesSince(int $tenantId, int $userId, string $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM lab_sessions WHERE tenant_id = ? AND user_id = ? AND started_at >= ?',
            [$tenantId, $userId, $since],
        );
    }

    /**
     * @param array<string, mixed>                                    $evidence
     * @param list<array{label: string, ok: bool, detail: string}> $feedback
     */
    public function markSubmitted(int $tenantId, int $id, int $score, bool $passed, array $evidence, array $feedback): void
    {
        $this->db->execute(
            'UPDATE lab_sessions SET status = ?, ended_at = ?, score = ?, passed = ?, evidence = ?, feedback = ? WHERE tenant_id = ? AND id = ? AND status = ?',
            [
                LabSession::SUBMITTED, $this->clock->sqlNow(), $score, $passed ? 1 : 0,
                json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                json_encode($feedback, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $tenantId, $id, LabSession::OPEN,
            ],
        );
    }

    public function markClosed(int $tenantId, int $id, string $status): void
    {
        $this->db->execute(
            'UPDATE lab_sessions SET status = ?, ended_at = ? WHERE tenant_id = ? AND id = ? AND status = ?',
            [$status, $this->clock->sqlNow(), $tenantId, $id, LabSession::OPEN],
        );
    }

    /** Ends every open session past its deadline (plus grace). @return int how many */
    public function expireStale(string $before): int
    {
        return $this->db->execute(
            'UPDATE lab_sessions SET status = ?, ended_at = ? WHERE status = ? AND expires_at < ?',
            [LabSession::EXPIRED, $this->clock->sqlNow(), LabSession::OPEN, $before],
        );
    }

    /**
     * Best submitted score per enrolment and lab lesson, for the progress report.
     *
     * @return array<int, array<int, array{score: int, passed: bool}>> enrolment id => lesson id => best
     */
    public function bestForCourse(int $tenantId, int $courseId): array
    {
        $best = [];
        foreach (
            $this->db->fetchAll(
                'SELECT enrolment_id, lesson_id, MAX(score) AS score, MAX(passed) AS passed FROM lab_sessions
             WHERE tenant_id = ? AND course_id = ? AND status = ? GROUP BY enrolment_id, lesson_id',
                [$tenantId, $courseId, LabSession::SUBMITTED],
            ) as $r
        ) {
            $best[(int) $r['enrolment_id']][(int) $r['lesson_id']] = ['score' => (int) $r['score'], 'passed' => (bool) $r['passed']];
        }

        return $best;
    }

    /** @return array{open: int, week: int, passed_week: int} for the System page */
    public function usage(string $since): array
    {
        $row = $this->db->fetchOne(
            "SELECT SUM(status = 'open') AS open_now, SUM(started_at >= ?) AS week, SUM(started_at >= ? AND passed = 1) AS passed_week FROM lab_sessions",
            [$since, $since],
        );

        return ['open' => (int) ($row['open_now'] ?? 0), 'week' => (int) ($row['week'] ?? 0), 'passed_week' => (int) ($row['passed_week'] ?? 0)];
    }
}
