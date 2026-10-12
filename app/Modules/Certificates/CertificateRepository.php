<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

use App\Core\Clock;
use App\Core\Database\Connection;
use PDOException;

final class CertificateRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function findByCode(int $tenantId, string $code): ?Certificate
    {
        $row = $this->db->fetchOne('SELECT * FROM certificates WHERE tenant_id = ? AND code = ?', [$tenantId, $code]);

        return $row === null ? null : Certificate::fromRow($row);
    }

    /** Across tenants: for the operator's console only. */
    public function findByCodeAnyTenant(string $code): ?Certificate
    {
        $row = $this->db->fetchOne('SELECT * FROM certificates WHERE code = ?', [$code]);

        return $row === null ? null : Certificate::fromRow($row);
    }

    public function findForEnrolment(int $tenantId, int $enrolmentId): ?Certificate
    {
        $row = $this->db->fetchOne('SELECT * FROM certificates WHERE tenant_id = ? AND enrolment_id = ?', [$tenantId, $enrolmentId]);

        return $row === null ? null : Certificate::fromRow($row);
    }

    /** @return list<Certificate> newest first */
    public function forUser(int $tenantId, int $userId): array
    {
        return array_map(Certificate::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM certificates WHERE tenant_id = ? AND user_id = ? ORDER BY issued_at DESC, id DESC LIMIT 100',
            [$tenantId, $userId],
        ));
    }

    /**
     * @return bool false when the code is already taken (caller retries with another)
     */
    public function insert(int $tenantId, int $enrolmentId, int $courseId, int $userId, string $code, string $learnerName, string $courseTitle): bool
    {
        try {
            $this->db->execute(
                'INSERT INTO certificates (tenant_id, enrolment_id, course_id, user_id, code, learner_name, course_title, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenantId, $enrolmentId, $courseId, $userId, $code, $learnerName, $courseTitle, $this->clock->sqlNow()],
            );

            return true;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && $this->findForEnrolment($tenantId, $enrolmentId) === null) {
                return false; // code collision, not a second certificate
            }
            if (($e->errorInfo[1] ?? null) === 1062) {
                return true; // already issued for this enrolment (concurrent completion)
            }
            throw $e;
        }
    }

    public function revoke(int $id, string $reason): void
    {
        $this->db->execute(
            'UPDATE certificates SET revoked_at = COALESCE(revoked_at, ?), revoked_reason = ? WHERE id = ?',
            [$this->clock->sqlNow(), $reason, $id],
        );
    }
}
