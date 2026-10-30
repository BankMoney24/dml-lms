<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

/**
 * One question in a bank: two to five options (A to E) and the index of the
 * correct one (0 = A). The prompt and explanation are Markdown (the lesson
 * subset); options are plain text.
 */
final class BankQuestion
{
    public const LETTERS = ['A', 'B', 'C', 'D', 'E'];
    public const DIFFICULTY = [1 => 'Easy', 2 => 'Medium', 3 => 'Hard'];

    /** @param list<string> $options */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $bankId,
        public readonly ?int $passageId,
        public readonly string $prompt,
        public readonly array $options,
        public readonly int $correct,
        public readonly string $explanation,
        public readonly string $topic,
        public readonly ?int $difficulty,
        public readonly int $timesSeen,
        public readonly int $timesCorrect,
        public readonly ?string $retiredAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        $options = json_decode((string) $r['options'], true);

        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (int) $r['bank_id'],
            isset($r['passage_id']) ? (int) $r['passage_id'] : null,
            (string) $r['prompt'],
            is_array($options) ? array_values(array_map('strval', $options)) : [],
            (int) $r['correct'],
            (string) ($r['explanation'] ?? ''),
            (string) $r['topic'],
            isset($r['difficulty']) ? (int) $r['difficulty'] : null,
            (int) $r['times_seen'],
            (int) $r['times_correct'],
            isset($r['retired_at']) ? (string) $r['retired_at'] : null,
        );
    }

    public function correctLetter(): string
    {
        return self::LETTERS[$this->correct] ?? '?';
    }

    /** Share of candidates who got it right, once enough have seen it (null before 5). */
    public function facility(): ?int
    {
        return $this->timesSeen >= 5 ? (int) round($this->timesCorrect / $this->timesSeen * 100) : null;
    }
}
