<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Clock;
use App\Core\Database\Connection;

/**
 * Database-backed login throttling, applied per account identifier and per
 * client IP. Locks apply whether or not the account exists, so a lock does
 * not reveal account existence.
 */
final class LoginThrottle
{
    public const ACCOUNT_LIMIT = 5;
    public const IP_LIMIT = 30;
    public const WINDOW_SECONDS = 900;
    public const LOCK_SECONDS = 900;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function isLocked(int $tenantId, string $email, string $ip): bool
    {
        $now = $this->clock->sqlNow();
        $count = $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_throttles WHERE throttle_key IN (?, ?) AND locked_until > ?',
            [$this->accountKey($tenantId, $email), $this->ipKey($ip), $now],
        );

        return (int) $count > 0;
    }

    public function recordFailure(int $tenantId, string $email, string $ip): void
    {
        $this->bump($this->accountKey($tenantId, $email), self::ACCOUNT_LIMIT);
        $this->bump($this->ipKey($ip), self::IP_LIMIT);
    }

    public function clear(int $tenantId, string $email): void
    {
        $this->db->execute('DELETE FROM login_throttles WHERE throttle_key = ?', [$this->accountKey($tenantId, $email)]);
    }

    private function bump(string $key, int $limit): void
    {
        $now = $this->clock->now();
        $nowSql = $now->format('Y-m-d H:i:s');
        $windowStart = $now->modify('-' . self::WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $lockUntil = $now->modify('+' . self::LOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($key, $limit, $nowSql, $windowStart, $lockUntil): void {
            $row = $this->db->fetchOne(
                'SELECT failures, window_started_at FROM login_throttles WHERE throttle_key = ? FOR UPDATE',
                [$key],
            );
            if ($row === null || (string) $row['window_started_at'] < $windowStart) {
                $this->db->execute(
                    'REPLACE INTO login_throttles (throttle_key, failures, window_started_at, locked_until) VALUES (?, 1, ?, NULL)',
                    [$key, $nowSql],
                );

                return;
            }
            $failures = (int) $row['failures'] + 1;
            $this->db->execute(
                'UPDATE login_throttles SET failures = ?, locked_until = ? WHERE throttle_key = ?',
                [$failures, $failures >= $limit ? $lockUntil : null, $key],
            );
        });
    }

    private function accountKey(int $tenantId, string $email): string
    {
        return hash('sha256', 'acct|' . $tenantId . '|' . UserRepository::normaliseEmail($email));
    }

    private function ipKey(string $ip): string
    {
        return hash('sha256', 'ip|' . $ip);
    }
}
