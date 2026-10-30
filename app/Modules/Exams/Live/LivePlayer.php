<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

final class LivePlayer
{
    public function __construct(
        public readonly int $id,
        public readonly int $sessionId,
        public readonly ?int $userId,
        public readonly string $nickname,
        public readonly int $score,
        public readonly int $streak,
        public readonly int $correctCount,
        public readonly ?int $seenPosition,
        public readonly ?string $seenAt,
        public readonly ?string $removedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['session_id'],
            isset($r['user_id']) ? (int) $r['user_id'] : null,
            (string) $r['nickname'],
            (int) $r['score'],
            (int) $r['streak'],
            (int) $r['correct_count'],
            isset($r['seen_position']) ? (int) $r['seen_position'] : null,
            isset($r['seen_at']) ? (string) $r['seen_at'] : null,
            isset($r['removed_at']) ? (string) $r['removed_at'] : null,
        );
    }
}
