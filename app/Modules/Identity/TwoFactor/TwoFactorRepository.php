<?php

declare(strict_types=1);

namespace App\Modules\Identity\TwoFactor;

use App\Core\Clock;
use App\Core\Database\Connection;

final class TwoFactorRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @return array{secret: string, last_step: ?int}|null */
    public function find(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT totp_secret, totp_last_step FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL',
            [$userId],
        );
        if ($row === null || $row['totp_secret'] === null) {
            return null;
        }

        return [
            'secret' => (string) $row['totp_secret'],
            'last_step' => $row['totp_last_step'] === null ? null : (int) $row['totp_last_step'],
        ];
    }

    public function enable(int $userId, string $sealedSecret, int $step): void
    {
        $this->db->execute(
            'UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ?, updated_at = ? WHERE id = ?',
            [$sealedSecret, $this->clock->sqlNow(), $step, $this->clock->sqlNow(), $userId],
        );
    }

    public function disable(int $userId): void
    {
        $this->db->transaction(function () use ($userId): void {
            $this->db->execute(
                'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = ? WHERE id = ?',
                [$this->clock->sqlNow(), $userId],
            );
            $this->db->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
        });
    }

    /** Records the step used, refusing to go backwards (concurrent replay of the same code). */
    public function consumeStep(int $userId, int $step): bool
    {
        return $this->db->execute(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, $userId, $step],
        ) === 1;
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $this->db->transaction(function () use ($userId, $hashes): void {
            $this->db->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
            foreach ($hashes as $hash) {
                $this->db->insert(
                    'INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)',
                    [$userId, $hash, $this->clock->sqlNow()],
                );
            }
        });
    }

    /** Marks a recovery code used; true only for an unused code of this user. */
    public function useRecoveryCode(int $userId, string $hash): bool
    {
        return $this->db->execute(
            'UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$this->clock->sqlNow(), $userId, $hash],
        ) === 1;
    }

    public function unusedRecoveryCodes(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId],
        );
    }
}
