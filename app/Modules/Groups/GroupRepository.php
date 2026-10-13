<?php

declare(strict_types=1);

namespace App\Modules\Groups;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Modules\Courses\Course;

/** Tenant-scoped storage for groups, their members and their courses. */
final class GroupRepository
{
    public const LEARNER = 'learner';
    public const TEACHER = 'teacher';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, string $name, ?string $level, ?string $period, int $userId): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO learner_groups (tenant_id, name, level, period_label, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $name, $level, $period, $userId, $now, $now],
        );
    }

    public function find(int $tenantId, int $groupId): ?LearnerGroup
    {
        $row = $this->db->fetchOne('SELECT * FROM learner_groups WHERE tenant_id = ? AND id = ?', [$tenantId, $groupId]);

        return $row === null ? null : LearnerGroup::fromRow($row);
    }

    public function nameTaken(int $tenantId, string $name, int $exceptId = 0): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM learner_groups WHERE tenant_id = ? AND name = ? AND id <> ?', [$tenantId, $name, $exceptId]) !== null;
    }

    public function update(int $tenantId, int $groupId, string $name, ?string $level, ?string $period): void
    {
        $this->db->execute(
            'UPDATE learner_groups SET name = ?, level = ?, period_label = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$name, $level, $period, $this->clock->sqlNow(), $tenantId, $groupId],
        );
    }

    public function setArchived(int $tenantId, int $groupId, bool $archived): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE learner_groups SET archived_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$archived ? $now : null, $now, $tenantId, $groupId],
        );
    }

    /**
     * Groups with their counts and teachers, for the list page. With $teacherId, only that teacher's groups.
     *
     * @return list<array{group: LearnerGroup, learners: int, courses: int, teachers: list<string>}>
     */
    public function overview(int $tenantId, ?int $teacherId = null, bool $archived = false): array
    {
        $params = [$tenantId];
        $where = $archived ? 'g.archived_at IS NOT NULL' : 'g.archived_at IS NULL';
        if ($teacherId !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM learner_group_members t WHERE t.tenant_id = g.tenant_id AND t.group_id = g.id AND t.user_id = ? AND t.role = ?)';
            $params[] = $teacherId;
            $params[] = self::TEACHER;
        }
        $rows = $this->db->fetchAll(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM learner_group_members m WHERE m.tenant_id = g.tenant_id AND m.group_id = g.id AND m.role = 'learner') AS learner_count,
                    (SELECT COUNT(*) FROM learner_group_courses c WHERE c.tenant_id = g.tenant_id AND c.group_id = g.id) AS course_count
             FROM learner_groups g WHERE g.tenant_id = ? AND $where ORDER BY g.level IS NULL, g.level, g.name LIMIT 500",
            $params,
        );
        $teachers = $this->teacherNames($tenantId, array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(static fn (array $r): array => [
            'group' => LearnerGroup::fromRow($r),
            'learners' => (int) $r['learner_count'],
            'courses' => (int) $r['course_count'],
            'teachers' => $teachers[(int) $r['id']] ?? [],
        ], $rows);
    }

    public function archivedCount(int $tenantId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM learner_groups WHERE tenant_id = ? AND archived_at IS NOT NULL', [$tenantId]);
    }

    public function isTeacher(int $tenantId, int $groupId, int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM learner_group_members WHERE tenant_id = ? AND group_id = ? AND user_id = ? AND role = ?',
            [$tenantId, $groupId, $userId, self::TEACHER],
        ) !== null;
    }

    public function teachesAny(int $tenantId, int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM learner_group_members m JOIN learner_groups g ON g.tenant_id = m.tenant_id AND g.id = m.group_id
             WHERE m.tenant_id = ? AND m.user_id = ? AND m.role = ? AND g.archived_at IS NULL LIMIT 1',
            [$tenantId, $userId, self::TEACHER],
        ) !== null;
    }

    /** @return list<array{user_id: int, name: string, email: string, added_at: string}> active members only, by name */
    public function members(int $tenantId, int $groupId, string $role): array
    {
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'name' => (string) $r['display_name'],
            'email' => (string) $r['email'],
            'added_at' => (string) $r['added_at'],
        ], $this->db->fetchAll(
            "SELECT gm.user_id, gm.added_at, u.display_name, u.email
             FROM learner_group_members gm
             JOIN memberships ms ON ms.tenant_id = gm.tenant_id AND ms.user_id = gm.user_id AND ms.status = 'active'
             JOIN users u ON u.id = gm.user_id
             WHERE gm.tenant_id = ? AND gm.group_id = ? AND gm.role = ? ORDER BY u.display_name LIMIT 2000",
            [$tenantId, $groupId, $role],
        ));
    }

    /**
     * Active learners of the school who are not in any current group, to tick when filling a new one.
     *
     * @return list<array{user_id: int, name: string, email: string}>
     */
    public function learnersWithoutGroup(int $tenantId, int $limit = 300): array
    {
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'name' => (string) $r['display_name'],
            'email' => (string) $r['email'],
        ], $this->db->fetchAll(
            "SELECT ms.user_id, u.display_name, u.email FROM memberships ms JOIN users u ON u.id = ms.user_id
             WHERE ms.tenant_id = ? AND ms.status = 'active' AND ms.role = 'learner'
               AND NOT EXISTS (SELECT 1 FROM learner_group_members gm JOIN learner_groups g ON g.tenant_id = gm.tenant_id AND g.id = gm.group_id
                               WHERE gm.tenant_id = ms.tenant_id AND gm.user_id = ms.user_id AND gm.role = 'learner' AND g.archived_at IS NULL)
             ORDER BY u.display_name LIMIT " . max(1, min($limit, 1000)),
            [$tenantId],
        ));
    }

    /** @return list<array{user_id: int, name: string, role: string}> staff who can teach a group */
    public function staff(int $tenantId): array
    {
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'name' => (string) $r['display_name'],
            'role' => (string) $r['role'],
        ], $this->db->fetchAll(
            "SELECT ms.user_id, ms.role, u.display_name FROM memberships ms JOIN users u ON u.id = ms.user_id
             WHERE ms.tenant_id = ? AND ms.status = 'active' AND ms.role IN ('owner', 'admin', 'educator') ORDER BY u.display_name LIMIT 500",
            [$tenantId],
        ));
    }

    /** @return ?string the member's role in the group, if any */
    public function roleOf(int $tenantId, int $groupId, int $userId): ?string
    {
        $role = $this->db->fetchValue('SELECT role FROM learner_group_members WHERE tenant_id = ? AND group_id = ? AND user_id = ?', [$tenantId, $groupId, $userId]);

        return is_string($role) ? $role : null;
    }

    public function addMember(int $tenantId, int $groupId, int $userId, string $role, int $addedBy): void
    {
        $this->db->execute(
            'INSERT INTO learner_group_members (tenant_id, group_id, user_id, role, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)',
            [$tenantId, $groupId, $userId, $role, $addedBy, $this->clock->sqlNow()],
        );
    }

    public function removeMember(int $tenantId, int $groupId, int $userId, string $role): void
    {
        $this->db->execute('DELETE FROM learner_group_members WHERE tenant_id = ? AND group_id = ? AND user_id = ? AND role = ?', [$tenantId, $groupId, $userId, $role]);
    }

    /** @return list<array{course: Course, due_on: ?string}> */
    public function courses(int $tenantId, int $groupId): array
    {
        return array_map(static fn (array $r): array => [
            'course' => Course::fromRow($r),
            'due_on' => isset($r['due_on']) ? (string) $r['due_on'] : null,
        ], $this->db->fetchAll(
            'SELECT c.*, gc.due_on FROM learner_group_courses gc JOIN courses c ON c.tenant_id = gc.tenant_id AND c.id = gc.course_id
             WHERE gc.tenant_id = ? AND gc.group_id = ? ORDER BY gc.due_on IS NULL, gc.due_on, c.title',
            [$tenantId, $groupId],
        ));
    }

    public function assignCourse(int $tenantId, int $groupId, int $courseId, ?string $dueOn, int $by): void
    {
        $this->db->execute(
            'INSERT INTO learner_group_courses (tenant_id, group_id, course_id, due_on, assigned_by, assigned_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE due_on = VALUES(due_on)',
            [$tenantId, $groupId, $courseId, $dueOn, $by, $this->clock->sqlNow()],
        );
    }

    public function unassignCourse(int $tenantId, int $groupId, int $courseId): void
    {
        $this->db->execute('DELETE FROM learner_group_courses WHERE tenant_id = ? AND group_id = ? AND course_id = ?', [$tenantId, $groupId, $courseId]);
    }

    /**
     * The groups a learner is in (not archived), with teachers and courses, for their home page.
     *
     * @return list<array{group: LearnerGroup, teachers: list<string>, courses: list<array{course: Course, due_on: ?string}>}>
     */
    public function forLearner(int $tenantId, int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT g.* FROM learner_groups g JOIN learner_group_members m ON m.tenant_id = g.tenant_id AND m.group_id = g.id
             WHERE g.tenant_id = ? AND m.user_id = ? AND m.role = ? AND g.archived_at IS NULL ORDER BY g.name LIMIT 20',
            [$tenantId, $userId, self::LEARNER],
        );
        $teachers = $this->teacherNames($tenantId, array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(fn (array $r): array => [
            'group' => LearnerGroup::fromRow($r),
            'teachers' => $teachers[(int) $r['id']] ?? [],
            'courses' => array_values(array_filter($this->courses($tenantId, (int) $r['id']), static fn (array $c): bool => $c['course']->isPublished())),
        ], $rows);
    }

    /**
     * @param list<int> $groupIds
     * @return array<int, list<string>> group id => teacher names
     */
    private function teacherNames(int $tenantId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $names = [];
        foreach (
            $this->db->fetchAll(
                "SELECT m.group_id, u.display_name FROM learner_group_members m JOIN users u ON u.id = m.user_id
                 JOIN memberships ms ON ms.tenant_id = m.tenant_id AND ms.user_id = m.user_id AND ms.status = 'active'
                 WHERE m.tenant_id = ? AND m.role = 'teacher' AND m.group_id IN (" . implode(',', array_fill(0, count($groupIds), '?')) . ')
                 ORDER BY u.display_name',
                array_merge([$tenantId], $groupIds),
            ) as $r
        ) {
            $names[(int) $r['group_id']][] = (string) $r['display_name'];
        }

        return $names;
    }
}
