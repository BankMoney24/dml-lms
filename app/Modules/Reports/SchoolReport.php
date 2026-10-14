<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Clock;
use App\Core\Database\Connection;

/**
 * School-wide numbers (ADR 0022, ADM-001): totals, every course, every learner.
 * Each figure comes from one aggregate query over indexed columns; nothing is
 * cached, so the page always matches what learners see.
 */
final class SchoolReport
{
    public const MAX_EXPORT = 10000;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return array{learners: int, staff: int, new_learners_30: int, active_7: int, active_30: int, enrolments: int, completions: int,
     *               completions_30: int, certificates: int, questions_30: int}
     */
    public function totals(int $tenantId): array
    {
        $d7 = $this->daysAgo(7);
        $d30 = $this->daysAgo(30);
        $members = $this->db->fetchOne(
            "SELECT SUM(role = 'learner') AS learners, SUM(role IN ('owner', 'admin', 'educator')) AS staff, SUM(role = 'learner' AND created_at >= ?) AS new_30
             FROM memberships WHERE tenant_id = ? AND status = 'active'",
            [$d30, $tenantId],
        ) ?? [];
        $enrol = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, SUM(completed_at IS NOT NULL) AS done, SUM(completed_at >= ?) AS done_30 FROM enrolments WHERE tenant_id = ? AND status <> 'withdrawn'",
            [$d30, $tenantId],
        ) ?? [];
        $active = static fn (string $since): string => 'SELECT COUNT(DISTINCT e.user_id) FROM lesson_progress lp
             JOIN enrolments e ON e.tenant_id = lp.tenant_id AND e.id = lp.enrolment_id
             WHERE lp.tenant_id = ? AND lp.updated_at >= ?';

        return [
            'learners' => (int) ($members['learners'] ?? 0),
            'staff' => (int) ($members['staff'] ?? 0),
            'new_learners_30' => (int) ($members['new_30'] ?? 0),
            'active_7' => (int) $this->db->fetchValue($active($d7), [$tenantId, $d7]),
            'active_30' => (int) $this->db->fetchValue($active($d30), [$tenantId, $d30]),
            'enrolments' => (int) ($enrol['n'] ?? 0),
            'completions' => (int) ($enrol['done'] ?? 0),
            'completions_30' => (int) ($enrol['done_30'] ?? 0),
            'certificates' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM certificates WHERE tenant_id = ? AND revoked_at IS NULL', [$tenantId]),
            'questions_30' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM lesson_questions WHERE tenant_id = ? AND created_at >= ?', [$tenantId, $d30]),
        ];
    }

    /**
     * Confirmed payments per currency: all time and the last 30 days.
     *
     * @return list<array{currency: string, total: int, total_30: int, count: int}>
     */
    public function revenue(int $tenantId): array
    {
        return array_map(static fn (array $r): array => [
            'currency' => (string) $r['currency'],
            'total' => (int) $r['total'],
            'total_30' => (int) $r['total_30'],
            'count' => (int) $r['n'],
        ], $this->db->fetchAll(
            "SELECT currency, SUM(amount_minor) AS total, SUM(CASE WHEN paid_at >= ? THEN amount_minor ELSE 0 END) AS total_30, COUNT(*) AS n
             FROM payments WHERE tenant_id = ? AND status = 'paid' GROUP BY currency ORDER BY total DESC",
            [$this->daysAgo(30), $tenantId],
        ));
    }

    /**
     * Every course with how its learners are doing.
     *
     * @return list<array{id: int, title: string, slug: string, status: string, lessons: int, enrolled: int, in_progress: int, completed: int,
     *                    not_started: int, avg_done: int, completion_rate: int, last_activity: ?string}>
     */
    public function courses(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.title, c.slug, c.status, COALESCE(lc.n, 0) AS lessons,
                    COUNT(e.id) AS enrolled,
                    COALESCE(SUM(e.completed_at IS NOT NULL), 0) AS completed,
                    COALESCE(SUM(e.completed_at IS NULL AND COALESCE(p.touched, 0) > 0), 0) AS in_progress,
                    AVG(CASE WHEN e.id IS NOT NULL AND COALESCE(lc.n, 0) > 0 THEN LEAST(COALESCE(p.done, 0), lc.n) / lc.n END) AS avg_done,
                    MAX(p.last_at) AS last_activity
             FROM courses c
             LEFT JOIN (SELECT course_id, COUNT(*) AS n FROM lessons WHERE tenant_id = ? GROUP BY course_id) lc ON lc.course_id = c.id
             LEFT JOIN enrolments e ON e.tenant_id = c.tenant_id AND e.course_id = c.id AND e.status <> 'withdrawn'
             LEFT JOIN (SELECT enrolment_id, SUM(status = 'completed') AS done, COUNT(*) AS touched, MAX(updated_at) AS last_at
                        FROM lesson_progress WHERE tenant_id = ? GROUP BY enrolment_id) p ON p.enrolment_id = e.id
             WHERE c.tenant_id = ?
             GROUP BY c.id, c.title, c.slug, c.status, lc.n
             ORDER BY enrolled DESC, c.title
             LIMIT 500",
            [$tenantId, $tenantId, $tenantId],
        );

        return array_map(static function (array $r): array {
            $enrolled = (int) $r['enrolled'];
            $completed = (int) $r['completed'];
            $inProgress = (int) $r['in_progress'];

            return [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'slug' => (string) $r['slug'],
                'status' => (string) $r['status'],
                'lessons' => (int) $r['lessons'],
                'enrolled' => $enrolled,
                'in_progress' => $inProgress,
                'completed' => $completed,
                'not_started' => max(0, $enrolled - $completed - $inProgress),
                'avg_done' => $r['avg_done'] === null ? 0 : (int) round((float) $r['avg_done'] * 100),
                'completion_rate' => $enrolled === 0 ? 0 : (int) round($completed * 100 / $enrolled),
                'last_activity' => $r['last_activity'] === null ? null : (string) $r['last_activity'],
            ];
        }, $rows);
    }

    /**
     * Learners with their courses, progress and last activity. $find matches name
     * or email; $groupId keeps one class.
     *
     * @return list<array{user_id: int, name: string, email: string, joined: string, courses: int, completed: int, lessons_done: int,
     *                    certificates: int, last_activity: ?string}>
     */
    public function learners(int $tenantId, string $find = '', ?int $groupId = null, int $limit = 50, int $offset = 0): array
    {
        $where = ["m.tenant_id = ?", "m.status = 'active'", "m.role = 'learner'"];
        $params = [$tenantId, $tenantId, $tenantId, $tenantId];
        $find = trim($find);
        if ($find !== '') {
            $like = '%' . addcslashes(mb_substr($find, 0, 80), '%_\\') . '%';
            $where[] = '(u.display_name LIKE ? OR u.email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($groupId !== null) {
            $where[] = "EXISTS (SELECT 1 FROM learner_group_members g WHERE g.tenant_id = m.tenant_id AND g.group_id = ? AND g.user_id = m.user_id AND g.role = 'learner')";
            $params[] = $groupId;
        }
        $rows = $this->db->fetchAll(
            "SELECT u.id AS user_id, u.display_name AS name, u.email, m.created_at AS joined,
                    COALESCE(es.courses, 0) AS courses, COALESCE(es.completed, 0) AS completed, COALESCE(ps.done, 0) AS lessons_done,
                    COALESCE(cs.n, 0) AS certificates, ps.last_at AS last_activity
             FROM memberships m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN (SELECT user_id, COUNT(*) AS courses, SUM(completed_at IS NOT NULL) AS completed
                        FROM enrolments WHERE tenant_id = ? AND status <> 'withdrawn' GROUP BY user_id) es ON es.user_id = m.user_id
             LEFT JOIN (SELECT e.user_id, SUM(lp.status = 'completed') AS done, MAX(lp.updated_at) AS last_at
                        FROM lesson_progress lp JOIN enrolments e ON e.tenant_id = lp.tenant_id AND e.id = lp.enrolment_id
                        WHERE lp.tenant_id = ? GROUP BY e.user_id) ps ON ps.user_id = m.user_id
             LEFT JOIN (SELECT user_id, COUNT(*) AS n FROM certificates WHERE tenant_id = ? AND revoked_at IS NULL GROUP BY user_id) cs ON cs.user_id = m.user_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY (ps.last_at IS NULL), ps.last_at DESC, u.display_name
             LIMIT ' . max(1, min($limit, self::MAX_EXPORT)) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'joined' => (string) $r['joined'],
            'courses' => (int) $r['courses'],
            'completed' => (int) $r['completed'],
            'lessons_done' => (int) $r['lessons_done'],
            'certificates' => (int) $r['certificates'],
            'last_activity' => $r['last_activity'] === null ? null : (string) $r['last_activity'],
        ], $rows);
    }

    /** @return list<array{id: int, name: string}> current classes, for the filter */
    public function groups(int $tenantId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $this->db->fetchAll(
            'SELECT id, name FROM learner_groups WHERE tenant_id = ? AND archived_at IS NULL ORDER BY name LIMIT 200',
            [$tenantId],
        ));
    }

    private function daysAgo(int $days): string
    {
        return $this->clock->now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    }
}
