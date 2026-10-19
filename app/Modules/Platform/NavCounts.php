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

    /** @return array{reviews: int, support: int, children: int, requests: int, questions: int, applications: int, claims: int} */
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
