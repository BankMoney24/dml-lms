<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Clock;
use App\Core\Database\Connection;

/**
 * Single-use links for password reset and invitations. Only a SHA-256 hash
 * of the token is stored, so a database leak does not expose working links.
 */
final class AccountTokenRepository
{
    public const RESET = 'reset';
    public const INVITE = 'invite';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** Issues a new token and cancels earlier unused ones of the same purpose. Returns the raw token for the link. */
    public function issue(int $tenantId, int $userId, string $purpose, int $ttlSeconds, ?int $createdBy): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->db->transaction(function () use ($tenantId, $userId, $purpose, $ttlSeconds, $createdBy, $raw, $now): void {
            $this->db->execute(
                'DELETE FROM account_tokens WHERE tenant_id = ? AND user_id = ? AND purpose = ? AND used_at IS NULL',
                [$tenantId, $userId, $purpose],
            );
            $this->db->insert(
                'INSERT INTO account_tokens (tenant_id, user_id, purpose, token_hash, expires_at, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $tenantId, $userId, $purpose, self::hash($raw),
                    $now->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s'),
                    $createdBy, $now->format('Y-m-d H:i:s'),
                ],
            );
        });

        return $raw;
    }

    /** @return array{id: int, user_id: int, purpose: string}|null */
    public function findValid(int $tenantId, string $raw): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT id, user_id, purpose FROM account_tokens
             WHERE token_hash = ? AND tenant_id = ? AND used_at IS NULL AND expires_at > ?',
            [self::hash($raw), $tenantId, $this->clock->sqlNow()],
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'purpose' => (string) $row['purpose']];
    }

    /** Atomically spends the token; false if it was already used (double submit, race). */
    public function consume(int $tokenId): bool
    {
        return $this->db->execute(
            'UPDATE account_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL',
            [$this->clock->sqlNow(), $tokenId],
        ) === 1;
    }

    /** After a password change every outstanding link for the user stops working. */
    public function revokeAllForUser(int $userId): void
    {
        $this->db->execute(
            'UPDATE account_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
            [$this->clock->sqlNow(), $userId],
        );
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
