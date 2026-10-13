<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Security\SecretBox;

/**
 * A school's Paystack account. The secret key is sealed with APP_KEY and
 * only ever decrypted on the server to call Paystack or check a webhook
 * signature; it is never displayed, logged or sent to a browser (PAY-005).
 */
final class PaymentAccountRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly SecretBox $box)
    {
    }

    /** @return ?array{provider: string, mode: string, currency: string, connected_at: string, connected_by: string} */
    public function find(int $tenantId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT a.provider, a.mode, a.currency, a.connected_at, u.display_name FROM payment_accounts a JOIN users u ON u.id = a.connected_by WHERE a.tenant_id = ?',
            [$tenantId],
        );

        return $row === null ? null : [
            'provider' => (string) $row['provider'],
            'mode' => (string) $row['mode'],
            'currency' => (string) $row['currency'],
            'connected_at' => (string) $row['connected_at'],
            'connected_by' => (string) $row['display_name'],
        ];
    }

    public function secret(int $tenantId): ?string
    {
        $sealed = $this->db->fetchValue('SELECT secret_sealed FROM payment_accounts WHERE tenant_id = ?', [$tenantId]);

        return is_string($sealed) ? $this->box->decrypt($sealed) : null;
    }

    public function save(int $tenantId, string $secret, string $mode, string $currency, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO payment_accounts (tenant_id, provider, secret_sealed, mode, currency, connected_by, connected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE secret_sealed = VALUES(secret_sealed), mode = VALUES(mode), currency = VALUES(currency),
                                     connected_by = VALUES(connected_by), connected_at = VALUES(connected_at)',
            [$tenantId, 'paystack', $this->box->encrypt($secret), $mode, $currency, $userId, $this->clock->sqlNow()],
        );
    }

    public function delete(int $tenantId): void
    {
        $this->db->execute('DELETE FROM payment_accounts WHERE tenant_id = ?', [$tenantId]);
    }
}
