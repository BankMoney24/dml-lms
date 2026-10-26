<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Clock;
use App\Core\Database\Connection;

final class PaymentRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array{tenant_id: int, user_id: int, course_id: int, reference: string, provider: string, mode: string, amount_minor: int, currency: string,
     *     status: string, note?: ?string, recorded_by?: ?int, coupon_id?: ?int, list_price_minor?: ?int} $p
     */
    public function create(array $p): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, note, coupon_id, list_price_minor,
                                   recorded_by, created_at, updated_at, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $p['tenant_id'], $p['user_id'], $p['course_id'], $p['reference'], $p['provider'], $p['mode'], $p['amount_minor'], $p['currency'],
                $p['status'], $p['note'] ?? null, $p['coupon_id'] ?? null, $p['list_price_minor'] ?? null,
                $p['recorded_by'] ?? null, $now, $now, $p['status'] === Payment::PAID ? $now : null,
            ],
        );
    }

    /** A payment paid back in full no longer gives access (ADR 0032). */
    public function markRefunded(int $tenantId, int $id): void
    {
        $this->db->execute(
            'UPDATE payments SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status = ?',
            [Payment::REFUNDED, $this->clock->sqlNow(), $tenantId, $id, Payment::PAID],
        );
    }

    public function findLocked(int $tenantId, int $id): ?Payment
    {
        $row = $this->db->fetchOne('SELECT * FROM payments WHERE tenant_id = ? AND id = ? FOR UPDATE', [$tenantId, $id]);

        return $row === null ? null : Payment::fromRow($row);
    }

    public function find(int $tenantId, int $id): ?Payment
    {
        $row = $this->db->fetchOne('SELECT * FROM payments WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $row === null ? null : Payment::fromRow($row);
    }

    public function findByReference(int $tenantId, string $reference, bool $lock = false): ?Payment
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM payments WHERE tenant_id = ? AND reference = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$tenantId, $reference],
        );

        return $row === null ? null : Payment::fromRow($row);
    }

    public function markPaid(int $tenantId, int $id, ?string $transactionId, ?string $channel): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE payments SET status = ?, provider_transaction_id = ?, channel = ?, paid_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status <> ?',
            [Payment::PAID, $transactionId, $channel, $now, $now, $tenantId, $id, Payment::PAID],
        );
    }

    public function markFailed(int $tenantId, int $id, string $note): void
    {
        $this->db->execute(
            'UPDATE payments SET status = ?, note = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status = ?',
            [Payment::FAILED, mb_substr($note, 0, 255), $this->clock->sqlNow(), $tenantId, $id, Payment::PENDING],
        );
    }

    public function hasPaid(int $tenantId, int $userId, int $courseId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM payments WHERE tenant_id = ? AND user_id = ? AND course_id = ? AND status = ? LIMIT 1',
            [$tenantId, $userId, $courseId, Payment::PAID],
        ) !== null;
    }

    public function pendingSince(int $tenantId, int $userId, string $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND user_id = ? AND status = ? AND created_at >= ?',
            [$tenantId, $userId, Payment::PENDING, $since],
        );
    }

    /**
     * For the school's payments page and the CSV export, newest first.
     *
     * @return list<array{payment: Payment, learner: string, email: string, course: string}>
     */
    public function listForSchool(int $tenantId, ?string $status, int $limit = 200): array
    {
        $params = [$tenantId];
        $where = '';
        if ($status !== null) {
            $where = ' AND p.status = ?';
            $params[] = $status;
        }

        return array_map(static fn (array $r): array => [
            'payment' => Payment::fromRow($r),
            'learner' => (string) $r['display_name'],
            'email' => (string) $r['email'],
            'course' => (string) $r['course_title'],
        ], $this->db->fetchAll(
            'SELECT p.*, u.display_name, u.email, c.title AS course_title
             FROM payments p JOIN users u ON u.id = p.user_id JOIN courses c ON c.tenant_id = p.tenant_id AND c.id = p.course_id
             WHERE p.tenant_id = ?' . $where . ' ORDER BY p.id DESC LIMIT ' . max(1, min($limit, 5000)),
            $params,
        ));
    }

    /** @return ?array{payment: Payment, learner: string, email: string, course: string} one payment with who paid and for what */
    public function findForSchool(int $tenantId, int $id): ?array
    {
        $r = $this->db->fetchOne(
            'SELECT p.*, u.display_name, u.email, c.title AS course_title
             FROM payments p JOIN users u ON u.id = p.user_id JOIN courses c ON c.tenant_id = p.tenant_id AND c.id = p.course_id
             WHERE p.tenant_id = ? AND p.id = ?',
            [$tenantId, $id],
        );

        return $r === null ? null : ['payment' => Payment::fromRow($r), 'learner' => (string) $r['display_name'], 'email' => (string) $r['email'], 'course' => (string) $r['course_title']];
    }

    /** @return list<array{payment: Payment, course: string}> */
    public function forUser(int $tenantId, int $userId): array
    {
        return array_map(static fn (array $r): array => ['payment' => Payment::fromRow($r), 'course' => (string) $r['course_title']], $this->db->fetchAll(
            'SELECT p.*, c.title AS course_title FROM payments p JOIN courses c ON c.tenant_id = p.tenant_id AND c.id = p.course_id
             WHERE p.tenant_id = ? AND p.user_id = ? ORDER BY p.id DESC LIMIT 50',
            [$tenantId, $userId],
        ));
    }

    /** @return array{paid_count: int, paid_minor: int, currency: ?string} paid in the last 30 days */
    public function totals(int $tenantId, string $since): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS total, MIN(currency) AS currency FROM payments
             WHERE tenant_id = ? AND status = ? AND paid_at >= ? AND mode = ?',
            [$tenantId, Payment::PAID, $since, 'live'],
        ) ?? [];

        return ['paid_count' => (int) ($row['n'] ?? 0), 'paid_minor' => (int) ($row['total'] ?? 0), 'currency' => isset($row['currency']) ? (string) $row['currency'] : null];
    }

    /**
     * Records a status change or provider notification once. Returns false if
     * this exact event was already recorded (a repeated webhook).
     *
     * @param array<string, scalar|null> $details
     */
    public function recordEvent(int $tenantId, ?int $paymentId, string $key, string $source, string $type, string $outcome, array $details = []): bool
    {
        $exists = $this->db->fetchValue('SELECT 1 FROM payment_events WHERE tenant_id = ? AND event_key = ?', [$tenantId, $key]);
        if ($exists !== null) {
            return false;
        }
        try {
            $this->db->insert(
                'INSERT INTO payment_events (tenant_id, payment_id, event_key, source, type, outcome, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenantId, $paymentId, mb_substr($key, 0, 160), $source, mb_substr($type, 0, 60), mb_substr($outcome, 0, 60),
                 $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->clock->sqlNow()],
            );
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false; // the same event arrived twice at the same moment
            }
            throw $e;
        }

        return true;
    }

    /** @return list<array{type: string, outcome: string, source: string, created_at: string}> */
    public function events(int $tenantId, int $paymentId): array
    {
        return array_map(static fn (array $r): array => [
            'type' => (string) $r['type'],
            'outcome' => (string) $r['outcome'],
            'source' => (string) $r['source'],
            'created_at' => (string) $r['created_at'],
        ], $this->db->fetchAll(
            'SELECT type, outcome, source, created_at FROM payment_events WHERE tenant_id = ? AND payment_id = ? ORDER BY id',
            [$tenantId, $paymentId],
        ));
    }
}
