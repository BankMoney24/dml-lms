<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Clock;
use App\Core\Database\Connection;

final class PaymentClaimRepository
{
    private const SELECT = 'SELECT pc.*, c.title AS course_title, c.slug AS course_slug, c.price_minor, u.display_name AS learner_name, u.email AS learner_email
        FROM payment_claims pc
        JOIN courses c ON c.tenant_id = pc.tenant_id AND c.id = pc.course_id
        JOIN users u ON u.id = pc.user_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $userId, int $courseId, int $amountMinor, string $currency, string $method, string $paidOn, string $reference, string $note): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO payment_claims (tenant_id, user_id, course_id, amount_minor, currency, method, paid_on, payer_reference, note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $courseId, $amountMinor, $currency, $method, $paidOn, $reference, $note, PaymentClaim::PENDING, $now, $now],
        );
    }

    public function find(int $tenantId, int $id, bool $lock = false): ?PaymentClaim
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pc.tenant_id = ? AND pc.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);

        return $row === null ? null : PaymentClaim::fromRow($row);
    }

    /** The newest claim this learner made for this course. */
    public function latestFor(int $tenantId, int $userId, int $courseId): ?PaymentClaim
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pc.tenant_id = ? AND pc.user_id = ? AND pc.course_id = ? ORDER BY pc.id DESC LIMIT 1', [$tenantId, $userId, $courseId]);

        return $row === null ? null : PaymentClaim::fromRow($row);
    }

    /** @return list<PaymentClaim> */
    public function forUser(int $tenantId, int $userId): array
    {
        return array_map(PaymentClaim::fromRow(...), $this->db->fetchAll(self::SELECT . ' WHERE pc.tenant_id = ? AND pc.user_id = ? ORDER BY pc.id DESC LIMIT 50', [$tenantId, $userId]));
    }

    /** @return list<PaymentClaim> oldest first, so the school works through them in order */
    public function pending(int $tenantId, int $limit = 100): array
    {
        return array_map(PaymentClaim::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE pc.tenant_id = ? AND pc.status = ? ORDER BY pc.created_at, pc.id LIMIT ' . max(1, min(500, $limit)),
            [$tenantId, PaymentClaim::PENDING],
        ));
    }

    public function countPending(int $tenantId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM payment_claims WHERE tenant_id = ? AND status = ?', [$tenantId, PaymentClaim::PENDING]);
    }

    public function decide(int $tenantId, int $id, string $status, ?int $decidedBy, ?string $reason = null, ?int $paymentId = null): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE payment_claims SET status = ?, reason = ?, payment_id = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$status, $reason, $paymentId, $decidedBy, $now, $now, $tenantId, $id],
        );
    }

    public function instructions(int $tenantId): string
    {
        return (string) ($this->db->fetchValue('SELECT instructions FROM payment_instructions WHERE tenant_id = ?', [$tenantId]) ?? '');
    }

    public function saveInstructions(int $tenantId, string $text, int $userId): void
    {
        if ($text === '') {
            $this->db->execute('DELETE FROM payment_instructions WHERE tenant_id = ?', [$tenantId]);

            return;
        }
        $this->db->execute(
            'INSERT INTO payment_instructions (tenant_id, instructions, updated_by, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE instructions = VALUES(instructions), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$tenantId, $text, $userId, $this->clock->sqlNow()],
        );
    }
}
