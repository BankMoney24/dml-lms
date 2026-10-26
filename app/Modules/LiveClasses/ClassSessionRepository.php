<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses;

use App\Core\Clock;
use App\Core\Database\Connection;

final class ClassSessionRepository
{
    private const SELECT = 'SELECT s.*, g.name AS group_name, c.title AS course_title, c.slug AS course_slug
        FROM class_sessions s
        JOIN learner_groups g ON g.tenant_id = s.tenant_id AND g.id = s.group_id
        LEFT JOIN courses c ON c.tenant_id = s.tenant_id AND c.id = s.course_id';
    public const STATUSES = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** "Now" from the application clock, so pages and tests agree on what is live. */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @param array<string, mixed> $s */
    public function create(int $tenantId, array $s): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO class_sessions (tenant_id, group_id, course_id, series_id, title, description, starts_at, ends_at, mode, style, location, join_url, room_name,
                                         status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId, $s['group_id'], $s['course_id'], $s['series_id'], $s['title'], $s['description'], $s['starts_at'], $s['ends_at'],
                $s['mode'], $s['style'] ?? 'discussion', $s['location'], $s['join_url'], $s['room_name'] ?? null, 'scheduled', $s['created_by'], $now, $now,
            ],
        );
    }

    /** @param array<string, mixed> $s */
    public function update(int $tenantId, int $id, array $s): void
    {
        $this->db->execute(
            'UPDATE class_sessions SET title = ?, description = ?, course_id = ?, starts_at = ?, ends_at = ?, mode = ?, style = ?, location = ?, join_url = ?,
                    recording_url = ?, room_name = COALESCE(room_name, ?), updated_at = ?
             WHERE tenant_id = ? AND id = ?',
            [
                $s['title'], $s['description'], $s['course_id'], $s['starts_at'], $s['ends_at'], $s['mode'], $s['style'] ?? 'discussion', $s['location'], $s['join_url'],
                $s['recording_url'], $s['room_name'] ?? null, $this->clock->sqlNow(), $tenantId, $id,
            ],
        );
    }

    /** Moves the end of a class (finishing early, or adding time while it runs). */
    public function setEnd(int $tenantId, int $id, string $endsAtUtc): void
    {
        $this->db->execute('UPDATE class_sessions SET ends_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$endsAtUtc, $this->clock->sqlNow(), $tenantId, $id]);
    }

    /** The uploaded recording of a class (a file in its materials), or null for none. */
    public function setRecordingFile(int $tenantId, int $id, ?int $fileId): void
    {
        $this->db->execute('UPDATE class_sessions SET recording_file_id = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$fileId, $this->clock->sqlNow(), $tenantId, $id]);
    }

    /**
     * Records a join, a leave or a once-a-minute "still here" from the classroom
     * page. A "here" that comes sooner than 40 seconds after the last signal
     * is dropped, so a page left open in two tabs does not count double.
     */
    public function recordEvent(int $tenantId, int $sessionId, int $userId, string $type): bool
    {
        $now = $this->clock->now();
        if ($type === 'here') {
            $recent = $this->db->fetchValue(
                'SELECT COUNT(*) FROM class_events WHERE tenant_id = ? AND session_id = ? AND user_id = ? AND type IN (\'join\', \'here\') AND created_at > ?',
                [$tenantId, $sessionId, $userId, $now->modify('-40 seconds')->format('Y-m-d H:i:s')],
            );
            if ((int) $recent > 0) {
                return false;
            }
        }
        $this->db->insert(
            'INSERT INTO class_events (tenant_id, session_id, user_id, type, created_at) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $sessionId, $userId, $type, $now->format('Y-m-d H:i:s')],
        );

        return true;
    }

    /**
     * About how many minutes each person spent in the classroom: the number of
     * different minutes in which their page said it was there, between the
     * room opening and the end of the class.
     *
     * @return array<int, int> user id => minutes
     */
    public function minutesInClassroom(int $tenantId, ClassSession $session): array
    {
        $opens = gmdate('Y-m-d H:i:s', $session->startTs() - ClassSession::JOIN_EARLY_MINUTES * 60);
        $out = [];
        foreach (
            $this->db->fetchAll(
                "SELECT user_id, COUNT(DISTINCT TIMESTAMPDIFF(MINUTE, ?, created_at)) AS minutes
                 FROM class_events WHERE tenant_id = ? AND session_id = ? AND type IN ('join', 'here') AND created_at >= ? AND created_at <= ?
                 GROUP BY user_id",
                [$opens, $tenantId, $session->id, $opens, $session->endsAt],
            ) as $row
        ) {
            $out[(int) $row['user_id']] = min((int) $row['minutes'], $session->minutes() + ClassSession::JOIN_EARLY_MINUTES);
        }

        return $out;
    }

    public function cancel(int $tenantId, int $id, string $reason): void
    {
        $this->db->execute(
            "UPDATE class_sessions SET status = 'cancelled', cancelled_reason = ?, updated_at = ? WHERE tenant_id = ? AND id = ?",
            [$reason, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function find(int $tenantId, int $id): ?ClassSession
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE s.tenant_id = ? AND s.id = ?', [$tenantId, $id]);

        return $row === null ? null : ClassSession::fromRow($row);
    }

    /** @return list<ClassSession> */
    public function forGroup(int $tenantId, int $groupId, string $fromUtc, string $toUtc, int $limit = 60): array
    {
        return array_map(ClassSession::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE s.tenant_id = ? AND s.group_id = ? AND s.ends_at >= ? AND s.starts_at < ? ORDER BY s.starts_at LIMIT ' . max(1, min(500, $limit)),
            [$tenantId, $groupId, $fromUtc, $toUtc],
        ));
    }

    /** @return list<ClassSession> the most recent first */
    public function pastForGroup(int $tenantId, int $groupId, string $beforeUtc, int $limit = 20): array
    {
        return array_map(ClassSession::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE s.tenant_id = ? AND s.group_id = ? AND s.ends_at < ? ORDER BY s.starts_at DESC LIMIT ' . max(1, min(500, $limit)),
            [$tenantId, $groupId, $beforeUtc],
        ));
    }

    /**
     * Sessions of the groups a learner belongs to, from a moment on.
     *
     * @return list<ClassSession>
     */
    public function forLearner(int $tenantId, int $userId, string $fromUtc, string $toUtc, int $limit = 30): array
    {
        return array_map(ClassSession::fromRow(...), $this->db->fetchAll(
            self::SELECT . " JOIN learner_group_members m ON m.tenant_id = s.tenant_id AND m.group_id = s.group_id AND m.user_id = ? AND m.role = 'learner'
             WHERE s.tenant_id = ? AND g.archived_at IS NULL AND s.ends_at >= ? AND s.starts_at < ? ORDER BY s.starts_at LIMIT " . max(1, min(500, $limit)),
            [$userId, $tenantId, $fromUtc, $toUtc],
        ));
    }

    /**
     * Sessions of groups this person teaches (all groups when $teacherId is null).
     *
     * @return list<ClassSession>
     */
    public function forTeacher(int $tenantId, ?int $teacherId, string $fromUtc, string $toUtc, int $limit = 60): array
    {
        $join = $teacherId === null ? '' : " JOIN learner_group_members t ON t.tenant_id = s.tenant_id AND t.group_id = s.group_id AND t.user_id = ? AND t.role = 'teacher'";
        $params = $teacherId === null ? [] : [$teacherId];

        return array_map(ClassSession::fromRow(...), $this->db->fetchAll(
            self::SELECT . $join . ' WHERE s.tenant_id = ? AND g.archived_at IS NULL AND s.ends_at >= ? AND s.starts_at < ? ORDER BY s.starts_at LIMIT ' . max(1, min(500, $limit)),
            [...$params, $tenantId, $fromUtc, $toUtc],
        ));
    }

    /**
     * Ended, not cancelled sessions with no marks yet: registers still to take.
     *
     * @return list<ClassSession>
     */
    public function registersToTake(int $tenantId, ?int $teacherId, string $nowUtc, string $sinceUtc): array
    {
        $join = $teacherId === null ? '' : " JOIN learner_group_members t ON t.tenant_id = s.tenant_id AND t.group_id = s.group_id AND t.user_id = ? AND t.role = 'teacher'";
        $params = $teacherId === null ? [] : [$teacherId];

        return array_map(ClassSession::fromRow(...), $this->db->fetchAll(
            self::SELECT . $join . " WHERE s.tenant_id = ? AND g.archived_at IS NULL AND s.status = 'scheduled' AND s.ends_at < ? AND s.ends_at >= ?
               AND NOT EXISTS (SELECT 1 FROM class_attendance a WHERE a.tenant_id = s.tenant_id AND a.session_id = s.id AND a.marked_by IS NOT NULL)
             ORDER BY s.starts_at DESC LIMIT 20",
            [...$params, $tenantId, $nowUtc, $sinceUtc],
        ));
    }

    /** @return array<int, array{status: string, note: string, self_checked_in: bool, marked_by: ?int}> user id => mark */
    public function marks(int $tenantId, int $sessionId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT user_id, status, note, self_checked_in, marked_by FROM class_attendance WHERE tenant_id = ? AND session_id = ?', [$tenantId, $sessionId]) as $r) {
            $out[(int) $r['user_id']] = [
                'status' => (string) $r['status'],
                'note' => (string) $r['note'],
                'self_checked_in' => (bool) $r['self_checked_in'],
                'marked_by' => isset($r['marked_by']) ? (int) $r['marked_by'] : null,
            ];
        }

        return $out;
    }

    /** @return ?array{status: string, note: string, self_checked_in: bool} */
    public function markFor(int $tenantId, int $sessionId, int $userId): ?array
    {
        $r = $this->db->fetchOne('SELECT status, note, self_checked_in FROM class_attendance WHERE tenant_id = ? AND session_id = ? AND user_id = ?', [$tenantId, $sessionId, $userId]);

        return $r === null ? null : ['status' => (string) $r['status'], 'note' => (string) $r['note'], 'self_checked_in' => (bool) $r['self_checked_in']];
    }

    /** The learner marks themselves; never overwrites a mark already there. */
    public function checkIn(int $tenantId, int $sessionId, int $userId, string $status): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO class_attendance (tenant_id, session_id, user_id, status, note, self_checked_in, marked_by, marked_at) VALUES (?, ?, ?, ?, \'\', 1, NULL, ?)',
            [$tenantId, $sessionId, $userId, $status, $this->clock->sqlNow()],
        ) > 0;
    }

    public function mark(int $tenantId, int $sessionId, int $userId, string $status, string $note, int $by): void
    {
        $this->db->execute(
            'INSERT INTO class_attendance (tenant_id, session_id, user_id, status, note, self_checked_in, marked_by, marked_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)',
            [$tenantId, $sessionId, $userId, $status, $note, $by, $this->clock->sqlNow()],
        );
    }

    /**
     * Attendance per learner over the group's held sessions (ended, not
     * cancelled) since that learner joined the group. "Not marked" counts
     * held sessions with no mark for that learner.
     *
     * @return array{held: int, rows: array<int, array{held: int, present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int}>}
     */
    public function groupSummary(int $tenantId, int $groupId, string $nowUtc): array
    {
        $held = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM class_sessions WHERE tenant_id = ? AND group_id = ? AND status = 'scheduled' AND ends_at < ?",
            [$tenantId, $groupId, $nowUtc],
        );
        $rows = [];
        foreach (
            $this->db->fetchAll(
                "SELECT m.user_id, COUNT(s.id) AS held,
                        SUM(a.status = 'present') AS present, SUM(a.status = 'late') AS late,
                        SUM(a.status = 'absent') AS absent, SUM(a.status = 'excused') AS excused
                 FROM learner_group_members m
                 JOIN class_sessions s ON s.tenant_id = m.tenant_id AND s.group_id = m.group_id AND s.status = 'scheduled' AND s.ends_at < ? AND s.starts_at >= m.added_at
                 LEFT JOIN class_attendance a ON a.tenant_id = s.tenant_id AND a.session_id = s.id AND a.user_id = m.user_id
                 WHERE m.tenant_id = ? AND m.group_id = ? AND m.role = 'learner'
                 GROUP BY m.user_id",
                [$nowUtc, $tenantId, $groupId],
            ) as $r
        ) {
            $row = ['present' => (int) $r['present'], 'late' => (int) $r['late'], 'absent' => (int) $r['absent'], 'excused' => (int) $r['excused'], 'unmarked' => 0, 'rate' => null];
            $rows[(int) $r['user_id']] = ['held' => (int) $r['held']] + self::withRate($row, (int) $r['held']);
        }

        return ['held' => $held, 'rows' => $rows];
    }

    /**
     * One learner's attendance across all their groups.
     *
     * @return array{held: int, present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int, recent: list<array{session: ClassSession, status: ?string}>}
     */
    public function learnerSummary(int $tenantId, int $userId, string $nowUtc, int $recent = 8, ?string $fromUtc = null): array
    {
        $sessions = array_map(ClassSession::fromRow(...), $this->db->fetchAll(
            self::SELECT . " JOIN learner_group_members m ON m.tenant_id = s.tenant_id AND m.group_id = s.group_id AND m.user_id = ? AND m.role = 'learner'
             WHERE s.tenant_id = ? AND s.status = 'scheduled' AND s.ends_at < ? AND s.starts_at >= m.added_at"
             . ($fromUtc !== null ? ' AND s.starts_at >= ?' : '') . ' ORDER BY s.starts_at DESC LIMIT ' . ($fromUtc !== null ? 1000 : 200),
            $fromUtc !== null ? [$userId, $tenantId, $nowUtc, $fromUtc] : [$userId, $tenantId, $nowUtc],
        ));
        $marks = [];
        if ($sessions !== []) {
            $ids = array_map(static fn (ClassSession $s): int => $s->id, $sessions);
            foreach (
                $this->db->fetchAll(
                    'SELECT session_id, status FROM class_attendance WHERE tenant_id = ? AND user_id = ? AND session_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                    [$tenantId, $userId, ...$ids],
                ) as $r
            ) {
                $marks[(int) $r['session_id']] = (string) $r['status'];
            }
        }
        $count = static fn (string $status): int => count(array_filter($sessions, static fn (ClassSession $s): bool => ($marks[$s->id] ?? null) === $status));
        $row = ['present' => $count('present'), 'late' => $count('late'), 'absent' => $count('absent'), 'excused' => $count('excused'), 'unmarked' => 0, 'rate' => null];

        return ['held' => count($sessions)] + self::withRate($row, count($sessions)) + [
            'recent' => array_map(static fn (ClassSession $s): array => ['session' => $s, 'status' => $marks[$s->id] ?? null], array_slice($sessions, 0, $recent)),
        ];
    }

    /**
     * @param array{present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int} $row
     * @return array{present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int}
     */
    private static function withRate(array $row, int $held): array
    {
        $marked = $row['present'] + $row['late'] + $row['absent'] + $row['excused'];
        $row['unmarked'] = max(0, $held - $marked);
        // Only classes with a mark count (no register yet is not the learner's absence); excused ones do not count against them.
        $counted = $marked - $row['excused'];
        $row['rate'] = $counted <= 0 ? null : (int) round(($row['present'] + $row['late']) * 100 / $counted);

        return $row;
    }
}
