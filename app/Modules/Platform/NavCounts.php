<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database\Connection;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;

/**
 * The small numbers in the sidebar: work waiting for this person. Each query
 * runs only for people whose role uses that link, and each is one indexed COUNT.
 */
final class NavCounts
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{reviews: int, support: int, children: int, requests: int, questions: int, applications: int, claims: int, in_group: int, teaches_group: int,
     *               registers: int, meetings: int}
     */
    public function for(CurrentUser $current, int $tenantId): array
    {
        $userId = $current->user->id;

        return [
            'reviews' => $current->can(Permission::CourseManage)
                ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM assignment_submissions WHERE tenant_id = ? AND status = 'submitted'", [$tenantId])
                : 0,
            'support' => $current->can(Permission::SupportManage)
                ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM support_requests WHERE tenant_id = ? AND status = 'open'", [$tenantId])
                : 0,
            'applications' => $current->can(Permission::CourseManage)
                ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM course_applications WHERE tenant_id = ? AND status = 'waiting'", [$tenantId])
                : 0,
            'claims' => $current->can(Permission::SchoolManage)
                ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM payment_claims WHERE tenant_id = ? AND status = 'pending'", [$tenantId])
                : 0,
            // Live classes (ADR 0028): who sees the link, and registers left to take in the last 30 days.
            'in_group' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM learner_group_members WHERE tenant_id = ? AND user_id = ? AND role = 'learner'", [$tenantId, $userId]),
            'teaches_group' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM learner_group_members WHERE tenant_id = ? AND user_id = ? AND role = 'teacher'", [$tenantId, $userId]),
            'registers' => (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM class_sessions s
                 JOIN learner_group_members t ON t.tenant_id = s.tenant_id AND t.group_id = s.group_id AND t.user_id = ? AND t.role = 'teacher'
                 WHERE s.tenant_id = ? AND s.status = 'scheduled' AND s.ends_at < UTC_TIMESTAMP() AND s.ends_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
                   AND NOT EXISTS (SELECT 1 FROM class_attendance a WHERE a.tenant_id = s.tenant_id AND a.session_id = s.id AND a.marked_by IS NOT NULL)",
                [$userId, $tenantId],
            ),
            // Meetings (ADR 0030): ones this person is invited to or hosts in the next week.
            'meetings' => (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM meetings m WHERE m.tenant_id = ? AND m.status = 'scheduled'
                   AND m.ends_at > UTC_TIMESTAMP() AND m.starts_at < UTC_TIMESTAMP() + INTERVAL 7 DAY
                   AND (m.host_id = ? OR EXISTS (SELECT 1 FROM meeting_invites i WHERE i.tenant_id = m.tenant_id AND i.meeting_id = m.id AND i.user_id = ?))",
                [$tenantId, $userId, $userId],
            ),
            'questions' => $current->can(Permission::CourseManage)
                ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM lesson_questions WHERE tenant_id = ? AND status = 'open' AND staff_answered = 0 AND accepted_answer_id IS NULL", [$tenantId])
                : 0,
            'requests' => $current->can(Permission::MembersManage)
                ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM join_requests WHERE tenant_id = ? AND status = 'pending'", [$tenantId])
                : 0,
            'children' => (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM guardian_links gl
                 JOIN memberships ml ON ml.tenant_id = gl.tenant_id AND ml.user_id = gl.learner_id AND ml.status = 'active'
                 WHERE gl.tenant_id = ? AND gl.guardian_id = ? AND gl.status = 'active'",
                [$tenantId, $userId],
            ),
        ];
    }
}
