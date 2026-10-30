<?php

declare(strict_types=1);

namespace App\Modules\Exams\Cbt;

/**
 * A computer-based test a school sets (ADR 0042). Sections are drawn from
 * question banks; some are compulsory, and when choose_count > 0 the candidate
 * picks that many of the others, as in the UTME (English plus three subjects).
 */
final class CbtExam
{
    public const RESULTS = [
        'immediately' => 'As soon as they submit',
        'after_close' => 'When the exam closes',
        'hidden' => 'Not shown to candidates',
    ];

    /**
     * @param list<array{bank: int, count: int, label: string, compulsory: bool}> $sections
     * @param list<int> $groupIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $title,
        public readonly string $instructions,
        public readonly array $sections,
        public readonly int $chooseCount,
        public readonly int $durationMinutes,
        public readonly ?string $opensAt,
        public readonly ?string $closesAt,
        public readonly string $audience,
        public readonly ?int $maxAttempts,
        public readonly string $results,
        public readonly bool $review,
        public readonly bool $shuffleQuestions,
        public readonly bool $shuffleOptions,
        public readonly ?string $accessCode,
        public readonly string $status,
        public readonly int $createdBy,
        public readonly array $groupIds = [],
    ) {
    }

    /**
     * @param array<string, mixed> $r
     * @param list<int> $groupIds
     */
    public static function fromRow(array $r, array $groupIds = []): self
    {
        $raw = json_decode((string) $r['sections'], true);
        $sections = [];
        foreach (is_array($raw) ? $raw : [] as $s) {
            if (is_array($s)) {
                $sections[] = ['bank' => (int) ($s['bank'] ?? 0), 'count' => (int) ($s['count'] ?? 0), 'label' => (string) ($s['label'] ?? ''), 'compulsory' => (bool) ($s['compulsory'] ?? false)];
            }
        }

        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (string) $r['title'],
            (string) ($r['instructions'] ?? ''),
            $sections,
            (int) $r['choose_count'],
            (int) $r['duration_minutes'],
            isset($r['opens_at']) ? (string) $r['opens_at'] : null,
            isset($r['closes_at']) ? (string) $r['closes_at'] : null,
            (string) $r['audience'],
            isset($r['max_attempts']) ? (int) $r['max_attempts'] : null,
            (string) $r['results'],
            (bool) $r['review'],
            (bool) $r['shuffle_questions'],
            (bool) $r['shuffle_options'],
            isset($r['access_code']) && $r['access_code'] !== '' ? (string) $r['access_code'] : null,
            (string) $r['status'],
            (int) $r['created_by'],
            $groupIds,
        );
    }

    /** Open for sitting at this moment (UTC "Y-m-d H:i:s"). */
    public function isOpen(string $now): bool
    {
        return $this->status === 'published' && ($this->opensAt === null || $this->opensAt <= $now) && ($this->closesAt === null || $this->closesAt > $now);
    }

    public function hasClosed(string $now): bool
    {
        return $this->status === 'closed' || ($this->closesAt !== null && $this->closesAt <= $now);
    }

    public function resultsVisible(string $now): bool
    {
        return match ($this->results) {
            'immediately' => true,
            'after_close' => $this->hasClosed($now),
            default => false,
        };
    }

    /** @return list<int> indexes of the sections everyone sits */
    public function compulsory(): array
    {
        if ($this->chooseCount === 0) {
            return array_keys($this->sections);
        }

        return array_keys(array_filter($this->sections, static fn (array $s): bool => $s['compulsory']));
    }

    /** @return list<int> indexes of the sections a candidate chooses from */
    public function optional(): array
    {
        return $this->chooseCount === 0 ? [] : array_keys(array_filter($this->sections, static fn (array $s): bool => !$s['compulsory']));
    }

    /** How many sections a candidate sits. */
    public function sectionsSat(): int
    {
        return count($this->compulsory()) + $this->chooseCount;
    }

    /** Questions in a paper when every section has the same count, or the range. */
    public function questionsLabel(): string
    {
        if ($this->chooseCount === 0) {
            return (string) array_sum(array_column($this->sections, 'count'));
        }
        $base = 0;
        foreach ($this->compulsory() as $i) {
            $base += $this->sections[$i]['count'];
        }
        $optional = array_map(fn (int $i): int => $this->sections[$i]['count'], $this->optional());
        sort($optional);
        $min = $base + array_sum(array_slice($optional, 0, $this->chooseCount));
        $max = $base + array_sum(array_slice(array_reverse($optional), 0, $this->chooseCount));

        return $min === $max ? (string) $min : $min . ' to ' . $max;
    }
}
