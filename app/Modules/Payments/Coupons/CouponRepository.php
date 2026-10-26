<?php

declare(strict_types=1);

namespace App\Modules\Payments\Coupons;

use App\Core\Clock;
use App\Core\Database\Connection;

final class CouponRepository
{
    private const SELECT = "SELECT k.*, c.title AS course_title,
            (SELECT COUNT(*) FROM payments p WHERE p.tenant_id = k.tenant_id AND p.coupon_id = k.id AND p.status = 'paid') AS uses
        FROM coupons k LEFT JOIN courses c ON c.tenant_id = k.tenant_id AND c.id = k.course_id";

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @param array{code: string, course_id: ?int, percent_off: ?int, amount_off_minor: ?int, currency: ?string, max_uses: ?int, expires_on: ?string, note: string} $c */
    public function create(int $tenantId, array $c, int $userId): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO coupons (tenant_id, code, course_id, percent_off, amount_off_minor, currency, max_uses, expires_on, status, note, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $c['code'], $c['course_id'], $c['percent_off'], $c['amount_off_minor'], $c['currency'], $c['max_uses'], $c['expires_on'],
             Coupon::ACTIVE, $c['note'] === '' ? null : $c['note'], $userId, $now, $now],
        );
    }

    public function find(int $tenantId, int $id): ?Coupon
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE k.tenant_id = ? AND k.id = ?', [$tenantId, $id]);

        return $row === null ? null : Coupon::fromRow($row);
    }

    /** With $lock, the code's row is locked first, so two people cannot take its last use at once. */
    public function findByCode(int $tenantId, string $code, bool $lock = false): ?Coupon
    {
        if ($lock) {
            $this->db->fetchOne('SELECT id FROM coupons WHERE tenant_id = ? AND code = ? FOR UPDATE', [$tenantId, Coupon::normalise($code)]);
        }
        $row = $this->db->fetchOne(self::SELECT . ' WHERE k.tenant_id = ? AND k.code = ?', [$tenantId, Coupon::normalise($code)]);

        return $row === null ? null : Coupon::fromRow($row);
    }

    /** @return list<Coupon> newest first */
    public function all(int $tenantId): array
    {
        return array_map(Coupon::fromRow(...), $this->db->fetchAll(self::SELECT . ' WHERE k.tenant_id = ? ORDER BY k.status, k.id DESC LIMIT 500', [$tenantId]));
    }

    public function setStatus(int $tenantId, int $id, string $status): void
    {
        $this->db->execute(
            'UPDATE coupons SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$status === Coupon::DISABLED ? Coupon::DISABLED : Coupon::ACTIVE, $this->clock->sqlNow(), $tenantId, $id],
        );
    }
}
