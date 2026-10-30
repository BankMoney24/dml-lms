<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

/**
 * One run of a live quiz (ADR 0042).
 *
 * status: lobby → question → reveal → scores → question … → reveal → finished.
 * Times have milliseconds (DATETIME(3)) in UTC.
 */
final class LiveSession
{
    /** @param list<array{prompt: string, options: list<string>, correct: int, seconds: int, points: string}> $questions */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $quizId,
        public readonly int $hostId,
        public readonly string $pin,
        public readonly string $title,
        public readonly array $questions,
        public readonly string $status,
        public readonly ?int $position,
        public readonly ?string $startedAt,
        public readonly ?string $endsAt,
        public readonly int $version,
        public readonly bool $allowGuests,
        public readonly bool $funNames,
        public readonly string $createdAt,
        public readonly ?string $endedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        $qs = json_decode((string) $r['questions'], true);
        $questions = [];
        foreach (is_array($qs) ? $qs : [] as $q) {
            if (is_array($q)) {
                $questions[] = [
                    'prompt' => (string) ($q['prompt'] ?? ''),
                    'options' => array_values(array_map('strval', is_array($q['options'] ?? null) ? $q['options'] : [])),
                    'correct' => (int) ($q['correct'] ?? 0),
                    'seconds' => (int) ($q['seconds'] ?? 20),
                    'points' => (string) ($q['points'] ?? 'standard'),
                ];
            }
        }

        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (int) $r['quiz_id'],
            (int) $r['host_id'],
            (string) $r['pin'],
            (string) $r['title'],
            $questions,
            (string) $r['status'],
            isset($r['position']) ? (int) $r['position'] : null,
            isset($r['question_started_at']) ? (string) $r['question_started_at'] : null,
            isset($r['question_ends_at']) ? (string) $r['question_ends_at'] : null,
            (int) $r['version'],
            (bool) $r['allow_guests'],
            (bool) $r['fun_names'],
            (string) $r['created_at'],
            isset($r['ended_at']) ? (string) $r['ended_at'] : null,
        );
    }

    /** @return ?array{prompt: string, options: list<string>, correct: int, seconds: int, points: string} */
    public function current(): ?array
    {
        return $this->position === null ? null : ($this->questions[$this->position] ?? null);
    }

    public function isLast(): bool
    {
        return $this->position !== null && $this->position >= count($this->questions) - 1;
    }

    /** Milliseconds left on the current question at $nowMs (epoch ms). */
    public function msLeft(int $nowMs): int
    {
        return $this->endsAt === null ? 0 : max(0, LiveClock::ms($this->endsAt) - $nowMs);
    }
}
