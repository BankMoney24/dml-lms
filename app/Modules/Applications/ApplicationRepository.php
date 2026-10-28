<?php

declare(strict_types=1);

namespace App\Modules\Applications;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Course applications. Every query is scoped by school. */
final class ApplicationRepository
{
    private const SELECT = 'SELECT a.*, c.title AS course_title, c.slug AS course_slug, d.display_name AS decider_name
        FROM course_applications a
        JOIN courses c ON c.tenant_id = a.tenant_id AND c.id = a.course_id
        LEFT JOIN users d ON d.id = a.decided_by';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $courseId, ?int $userId, string $name, string $email, string $phone, string $message): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO course_applications (tenant_id, course_id, user_id, name, email, phone, message, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $courseId, $userId, $name, $email, $phone, $message, Application::WAITING, $now, $now],
        );
    }

    public function find(int $tenantId, int $id, bool $lock = false): ?Application
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE a.tenant_id = ? AND a.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);

        return $row === null ? null : Application::fromRow($row);
    }

    /** The newest application for this course by this person (account or email). */
    public function latestFor(int $tenantId, int $courseId, ?int $userId, string $email): ?Application
    {
        $row = $this->db->fetchOne(
            self::SELECT . ' WHERE a.tenant_id = ? AND a.course_id = ? AND (a.user_id = ? OR a.email = ?) ORDER BY a.id DESC LIMIT 1',
            [$tenantId, $courseId, $userId ?? 0, $email],
        );

        return $row === null ? null : Application::fromRow($row);
    }

    /** @return list<Application> this person's applications, newest first */
    public function forUser(int $tenantId, int $userId): array
    {
        return array_map(Application::fromRow(...), $this->db->fetchAll(self::SELECT . ' WHERE a.tenant_id = ? AND a.user_id = ? ORDER BY a.id DESC LIMIT 50', [$tenantId, $userId]));
    }

    /** Whether this person was given a place (offered or already enrolled through an application). */
    public function hasPlace(int $tenantId, int $courseId, int $userId): bool
    {
        return $this->db->fetchValue(
            "SELECT 1 FROM course_applications WHERE tenant_id = ? AND course_id = ? AND user_id = ? AND status IN ('offered', 'enrolled') LIMIT 1",
            [$tenantId, $courseId, $userId],
        ) !== null;
    }

    /** Place in the queue for this course: 1 = next. */
    public function position(Application $application): int
    {
        return 1 + (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM course_applications WHERE tenant_id = ? AND course_id = ? AND status = 'waiting' AND (created_at < ? OR (created_at = ? AND id < ?))",
            [$application->tenantId, $application->courseId, $application->createdAt, $application->createdAt, $application->id],
        );
    }

    /**
     * @param 'waiting'|'offered'|'decided' $filter
     * @return list<Application>
     */
    public function queue(int $tenantId, string $filter, ?int $courseId = null, int $limit = 100, string $q = ''): array
    {
        $where = match ($filter) {
            'offered' => "a.status = 'offered'",
            'decided' => "a.status IN ('enrolled', 'declined', 'withdrawn')",
            default => "a.status = 'waiting'",
        };
        $params = [$tenantId];
        if ($courseId !== null) {
            $where .= ' AND a.course_id = ?';
            $params[] = $courseId;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (a.name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return array_map(Application::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE a.tenant_id = ? AND ' . $where
                . ' ORDER BY ' . ($filter === 'waiting' ? 'a.created_at, a.id' : 'COALESCE(a.decided_at, a.updated_at) DESC, a.id DESC')
                . ' LIMIT ' . max(1, min($limit, 500)),
            $params,
        ));
    }

    /** @return array<int, string> course id => title, for courses that have had applications */
    public function courses(int $tenantId): array
    {
        $out = [];
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT c.id, c.title FROM course_applications a JOIN courses c ON c.tenant_id = a.tenant_id AND c.id = a.course_id WHERE a.tenant_id = ? ORDER BY c.title LIMIT 300',
            [$tenantId],
        );
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['title'];
        }

        return $out;
    }

    /** @return array{waiting: int, offered: int} */
    public function counts(int $tenantId): array
    {
        $row = $this->db->fetchOne(
            "SELECT SUM(status = 'waiting') AS waiting, SUM(status = 'offered') AS offered FROM course_applications WHERE tenant_id = ?",
            [$tenantId],
        ) ?? [];

        return ['waiting' => (int) ($row['waiting'] ?? 0), 'offered' => (int) ($row['offered'] ?? 0)];
    }

    public function waitingForCourse(int $tenantId, int $courseId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM course_applications WHERE tenant_id = ? AND course_id = ? AND status = 'waiting'", [$tenantId, $courseId]);
    }

    public function decide(int $tenantId, int $id, string $status, ?int $deciderId, ?int $userId = null): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE course_applications SET status = ?, decided_by = COALESCE(?, decided_by), decided_at = COALESCE(decided_at, ?), user_id = COALESCE(?, user_id), updated_at = ?
             WHERE tenant_id = ? AND id = ?',
            [$status, $deciderId, $deciderId !== null ? $now : null, $userId, $now, $tenantId, $id],
        );
    }

    /** Someone with an offered place has now joined (for example after paying). */
    public function markEnrolled(int $tenantId, int $courseId, int $userId): void
    {
        $this->db->execute(
            "UPDATE course_applications SET status = 'enrolled', updated_at = ? WHERE tenant_id = ? AND course_id = ? AND user_id = ? AND status IN ('offered', 'waiting')",
            [$this->clock->sqlNow(), $tenantId, $courseId, $userId],
        );
    }
}
