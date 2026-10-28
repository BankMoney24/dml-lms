<?php

declare(strict_types=1);

namespace App\Modules\Payments\Plans;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Modules\Payments\Payment;

/** Payment plans (ADR 0037). Every query is limited to one school, except the reminder job's list. */
final class PaymentPlanRepository
{
    private const SELECT = 'SELECT pp.*, u.display_name AS learner_name, u.email AS learner_email, c.title AS course_title, c.slug AS course_slug
        FROM payment_plans pp
        JOIN users u ON u.id = pp.user_id
        JOIN courses c ON c.tenant_id = pp.tenant_id AND c.id = pp.course_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $userId, int $courseId, int $parts, int $totalMinor, string $currency): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO payment_plans (tenant_id, user_id, course_id, parts, total_minor, currency, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $courseId, $parts, $totalMinor, $currency, PaymentPlan::PENDING, $now, $now],
        );
    }

    public function find(int $tenantId, int $id, bool $lock = false): ?PaymentPlan
    {
        if ($lock) {
            $this->db->fetchValue('SELECT id FROM payment_plans WHERE tenant_id = ? AND id = ? FOR UPDATE', [$tenantId, $id]);
        }
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pp.tenant_id = ? AND pp.id = ?', [$tenantId, $id]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** The learner's newest plan for a course that has not been replaced: pending, active, completed or written off. */
    public function current(int $tenantId, int $userId, int $courseId): ?PaymentPlan
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pp.tenant_id = ? AND pp.user_id = ? AND pp.course_id = ? ORDER BY pp.id DESC LIMIT 1', [$tenantId, $userId, $courseId]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** @return list<PaymentPlan> every plan of this learner, newest first */
    public function forUser(int $tenantId, int $userId): array
    {
        return $this->hydrate($this->db->fetchAll(
            self::SELECT . ' WHERE pp.tenant_id = ? AND pp.user_id = ? AND pp.status <> ? ORDER BY pp.id DESC LIMIT 50',
            [$tenantId, $userId, PaymentPlan::PENDING],
        ));
    }

    /**
     * Plans the school is following: still being paid, or finished in the last 60 days.
     *
     * @return list<PaymentPlan>
     */
    public function forSchool(int $tenantId, int $limit = 200): array
    {
        $since = $this->clock->now()->modify('-60 days')->format('Y-m-d H:i:s');

        return $this->hydrate($this->db->fetchAll(
            self::SELECT . ' WHERE pp.tenant_id = ? AND (pp.status = ? OR (pp.status IN (?, ?) AND pp.updated_at >= ?)) ORDER BY pp.started_at, pp.id LIMIT ' . max(1, $limit),
            [$tenantId, PaymentPlan::ACTIVE, PaymentPlan::COMPLETED, PaymentPlan::WRITTEN_OFF, $since],
        ));
    }

    /** @return list<PaymentPlan> active plans in every school, for the daily reminder job */
    public function activeEverywhere(int $limit = 2000): array
    {
        return $this->hydrate($this->db->fetchAll(self::SELECT . ' WHERE pp.status = ? ORDER BY pp.id LIMIT ' . max(1, $limit), [PaymentPlan::ACTIVE]));
    }

    /**
     * Brings the status in line with the parts paid: pending (none), active
     * (some; started when part 1 was paid) or completed (all). Written-off
     * plans stay written off.
     */
    public function sync(int $tenantId, int $id): void
    {
        $plan = $this->find($tenantId, $id, true);
        if ($plan === null || $plan->status === PaymentPlan::WRITTEN_OFF) {
            return;
        }
        $paid = count(array_unique($plan->paidParts));
        $status = $paid === 0 ? PaymentPlan::PENDING : ($paid >= $plan->parts ? PaymentPlan::COMPLETED : PaymentPlan::ACTIVE);
        $started = $plan->startedAt;
        if ($started === null && $plan->isPartPaid(1)) {
            $first = $this->db->fetchValue(
                'SELECT MIN(paid_at) FROM payments WHERE tenant_id = ? AND plan_id = ? AND plan_part = 1 AND status = ?',
                [$tenantId, $id, Payment::PAID],
            );
            $started = $first === null ? $this->clock->sqlNow() : (string) $first;
        }
        $this->db->execute(
            'UPDATE payment_plans SET status = ?, started_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$status, $started, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function writeOff(int $tenantId, int $id, int $byUserId, string $note): void
    {
        $this->db->execute(
            'UPDATE payment_plans SET status = ?, ended_by = ?, ended_note = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status IN (?, ?)',
            [PaymentPlan::WRITTEN_OFF, $byUserId, mb_substr($note, 0, 255), $this->clock->sqlNow(), $tenantId, $id, PaymentPlan::ACTIVE, PaymentPlan::PENDING],
        );
    }

    public function markReminded(int $tenantId, int $id, string $key): void
    {
        $this->db->execute('UPDATE payment_plans SET reminded = ? WHERE tenant_id = ? AND id = ?', [mb_substr($key, 0, 16), $tenantId, $id]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<PaymentPlan>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $byTenant = [];
        foreach ($rows as $r) {
            $byTenant[(int) $r['tenant_id']][] = (int) $r['id'];
        }
        $paid = [];
        foreach ($byTenant as $tenantId => $all) {
            foreach (array_chunk($all, 500) as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $found = $this->db->fetchAll(
                    "SELECT plan_id, plan_part FROM payments WHERE tenant_id = ? AND plan_id IN ($marks) AND status = ? AND plan_part IS NOT NULL",
                    [$tenantId, ...$ids, Payment::PAID],
                );
                foreach ($found as $p) {
                    $paid[(int) $p['plan_id']][] = (int) $p['plan_part'];
                }
            }
        }

        return array_map(static fn (array $r): PaymentPlan => PaymentPlan::fromRow($r, array_values(array_unique($paid[(int) $r['id']] ?? []))), $rows);
    }
}
