<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Fixed-window counter stored in the database, so limits hold across PHP workers and restarts. */
final class RateLimiter
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** Records one hit; returns false when the bucket is already over its limit for this window. */
    public function attempt(string $key, int $maxHits, int $windowSeconds): bool
    {
        $bucket = hash('sha256', $key);
        $now = $this->clock->now();
        $windowStart = $now->modify('-' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use ($bucket, $maxHits, $now, $windowStart): bool {
            $row = $this->db->fetchOne('SELECT hits, window_started_at FROM rate_limits WHERE bucket = ? FOR UPDATE', [$bucket]);
            if ($row === null || (string) $row['window_started_at'] < $windowStart) {
                $this->db->execute(
                    'REPLACE INTO rate_limits (bucket, hits, window_started_at) VALUES (?, 1, ?)',
                    [$bucket, $now->format('Y-m-d H:i:s')],
                );

                return true;
            }
            if ((int) $row['hits'] >= $maxHits) {
                return false;
            }
            $this->db->execute('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);

            return true;
        });
    }
}
