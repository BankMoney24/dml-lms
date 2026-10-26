<?php

declare(strict_types=1);

namespace App\Modules\Payments\Refunds;

use App\Core\Clock;
use App\Core\Database\Connection;

final class RefundRepository
{
    private const SELECT = 'SELECT r.*, u.display_name AS created_by_name FROM payment_refunds r JOIN users u ON u.id = r.created_by';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $paymentId, int $amount, string $currency, string $reason, string $status, ?string $providerId, bool $withdrew, int $userId): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO payment_refunds (tenant_id, payment_id, amount_minor, currency, reason, status, provider_refund_id, withdrew_learner, created_by, created_at, updated_at, processed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $paymentId, $amount, $currency, $reason, $status, $providerId === '' ? null : $providerId, $withdrew ? 1 : 0, $userId, $now, $now,
             $status === Refund::PROCESSED ? $now : null],
        );
    }

    public function find(int $tenantId, int $id): ?Refund
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE r.tenant_id = ? AND r.id = ?', [$tenantId, $id]);

        return $row === null ? null : Refund::fromRow($row);
    }

    /** Money already paid back or on its way back for this payment (failed refunds do not count). */
    public function activeTotal(int $tenantId, int $paymentId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM payment_refunds WHERE tenant_id = ? AND payment_id = ? AND status <> 'failed'",
            [$tenantId, $paymentId],
        );
    }

    /** @return list<Refund> */
    public function forPayment(int $tenantId, int $paymentId): array
    {
        return array_map(Refund::fromRow(...), $this->db->fetchAll(self::SELECT . ' WHERE r.tenant_id = ? AND r.payment_id = ? ORDER BY r.id', [$tenantId, $paymentId]));
    }

    /**
     * Refunds for many payments at once, for the payments list.
     *
     * @param list<int> $paymentIds
     * @return array<int, list<Refund>> payment id => refunds
     */
    public function forPayments(int $tenantId, array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }
        $out = [];
        $in = implode(',', array_fill(0, count($paymentIds), '?'));
        foreach ($this->db->fetchAll(self::SELECT . " WHERE r.tenant_id = ? AND r.payment_id IN ($in) ORDER BY r.id", array_merge([$tenantId], $paymentIds)) as $row) {
            $refund = Refund::fromRow($row);
            $out[$refund->paymentId][] = $refund;
        }

        return $out;
    }

    /** The oldest refund still waiting for Paystack on this payment, with this amount when given. */
    public function oldestPending(int $tenantId, int $paymentId, ?int $amount): ?Refund
    {
        $row = $this->db->fetchOne(
            self::SELECT . " WHERE r.tenant_id = ? AND r.payment_id = ? AND r.status = 'pending'" . ($amount !== null ? ' AND r.amount_minor = ?' : '') . ' ORDER BY r.id LIMIT 1 FOR UPDATE',
            $amount !== null ? [$tenantId, $paymentId, $amount] : [$tenantId, $paymentId],
        );

        return $row === null ? null : Refund::fromRow($row);
    }

    public function settle(int $tenantId, int $id, string $status, ?string $failure = null): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE payment_refunds SET status = ?, failure = ?, updated_at = ?, processed_at = ? WHERE tenant_id = ? AND id = ?',
            [$status, $failure === null ? null : mb_substr($failure, 0, 255), $now, $status === Refund::PROCESSED ? $now : null, $tenantId, $id],
        );
    }

    /** @return array{count: int, minor: int} refunds started since a date, for the payments page */
    public function totalsSince(int $tenantId, string $since): array
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(r.amount_minor), 0) AS total FROM payment_refunds r JOIN payments p ON p.tenant_id = r.tenant_id AND p.id = r.payment_id
             WHERE r.tenant_id = ? AND r.status <> 'failed' AND r.created_at >= ? AND p.mode = 'live'",
            [$tenantId, $since],
        ) ?? [];

        return ['count' => (int) ($row['n'] ?? 0), 'minor' => (int) ($row['total'] ?? 0)];
    }
}
