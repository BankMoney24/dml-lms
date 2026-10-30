<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

/** One question in a live quiz: two to four answers, one correct, a time limit and how many points it is worth. */
final class LiveQuestion
{
    public const POINTS = ['standard' => 'Standard points', 'double' => 'Double points', 'none' => 'No points (warm-up or poll)'];
    public const SECONDS = [5, 10, 20, 30, 45, 60, 90, 120, 240];

    /** @param list<string> $options */
    public function __construct(
        public readonly int $id,
        public readonly int $position,
        public readonly string $prompt,
        public readonly array $options,
        public readonly int $correct,
        public readonly int $seconds,
        public readonly string $points,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        $options = json_decode((string) $r['options'], true);

        return new self(
            (int) $r['id'],
            (int) $r['position'],
            (string) $r['prompt'],
            is_array($options) ? array_values(array_map('strval', $options)) : [],
            (int) $r['correct'],
            (int) $r['seconds'],
            (string) $r['points'],
        );
    }

    /** @return array{prompt: string, options: list<string>, correct: int, seconds: int, points: string} the copy a session keeps */
    public function snapshot(): array
    {
        return ['prompt' => $this->prompt, 'options' => $this->options, 'correct' => $this->correct, 'seconds' => $this->seconds, 'points' => $this->points];
    }
}
