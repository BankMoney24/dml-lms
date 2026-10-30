<?php

declare(strict_types=1);

namespace App\Modules\Exams\Cbt;

/**
 * One sitting of a CBT exam or a practice test (ADR 0042).
 *
 * paper:   {"sections": [{"label": "Physics", "bank": 3, "questions": [{"id": 12, "perm": [2, 0, 1, 3]}, …]}]}
 *          perm lists the original option indexes in the order this candidate sees them.
 * answers: {"12": 1, …}  question id => original option index
 * result:  {"sections": [{"label", "correct", "count", "scaled"}], "total", "max"}
 */
final class CbtAttempt
{
    /** Saves and submissions this long after the deadline are still accepted (network delay). */
    public const GRACE_SECONDS = 30;

    /**
     * @param array{sections: list<array{label: string, bank: int, questions: list<array{id: int, perm: list<int>}>}>} $paper
     * @param array<int, int> $answers
     * @param list<int> $flags
     * @param ?array{sections: list<array{label: string, correct: int, count: int, scaled: int}>, total: int, max: int} $result
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly ?int $examId,
        public readonly int $userId,
        public readonly string $title,
        public readonly array $paper,
        public readonly array $answers,
        public readonly array $flags,
        public readonly int $questionCount,
        public readonly string $startedAt,
        public readonly string $deadlineAt,
        public readonly int $extraMinutes,
        public readonly int $saveSeq,
        public readonly ?string $lastSavedAt,
        public readonly ?string $submittedAt,
        public readonly ?string $submitReason,
        public readonly ?array $result,
        public readonly ?int $correctCount,
        public readonly ?int $percent,
        public readonly string $userName = '',
        public readonly string $userEmail = '',
        public readonly int $awayCount = 0,
        public readonly int $awaySeconds = 0,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        $paper = json_decode((string) $r['paper'], true);
        $sections = [];
        foreach (is_array($paper['sections'] ?? null) ? $paper['sections'] : [] as $s) {
            $qs = [];
            foreach (is_array($s['questions'] ?? null) ? $s['questions'] : [] as $q) {
                $qs[] = ['id' => (int) ($q['id'] ?? 0), 'perm' => array_values(array_map('intval', is_array($q['perm'] ?? null) ? $q['perm'] : []))];
            }
            $sections[] = ['label' => (string) ($s['label'] ?? ''), 'bank' => (int) ($s['bank'] ?? 0), 'questions' => $qs];
        }
        $answers = [];
        $raw = json_decode((string) $r['answers'], true);
        foreach (is_array($raw) ? $raw : [] as $qid => $choice) {
            if (is_int($choice)) {
                $answers[(int) $qid] = $choice;
            }
        }
        $flags = json_decode((string) $r['flags'], true);
        $raw = isset($r['result']) ? json_decode((string) $r['result'], true) : null;
        $result = null;
        if (is_array($raw)) {
            $rs = [];
            foreach (is_array($raw['sections'] ?? null) ? $raw['sections'] : [] as $s) {
                $rs[] = ['label' => (string) ($s['label'] ?? ''), 'correct' => (int) ($s['correct'] ?? 0), 'count' => (int) ($s['count'] ?? 0), 'scaled' => (int) ($s['scaled'] ?? 0)];
            }
            $result = ['sections' => $rs, 'total' => (int) ($raw['total'] ?? 0), 'max' => (int) ($raw['max'] ?? 0)];
        }

        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            isset($r['exam_id']) ? (int) $r['exam_id'] : null,
            (int) $r['user_id'],
            (string) $r['title'],
            ['sections' => $sections],
            $answers,
            is_array($flags) ? array_values(array_map('intval', $flags)) : [],
            (int) $r['question_count'],
            (string) $r['started_at'],
            (string) $r['deadline_at'],
            (int) $r['extra_minutes'],
            (int) $r['save_seq'],
            isset($r['last_saved_at']) ? (string) $r['last_saved_at'] : null,
            isset($r['submitted_at']) ? (string) $r['submitted_at'] : null,
            isset($r['submit_reason']) ? (string) $r['submit_reason'] : null,
            $result,
            isset($r['correct_count']) ? (int) $r['correct_count'] : null,
            isset($r['percent']) ? (int) $r['percent'] : null,
            (string) ($r['user_name'] ?? ''),
            (string) ($r['user_email'] ?? ''),
            (int) ($r['away_count'] ?? 0),
            (int) ($r['away_seconds'] ?? 0),
        );
    }

    /** "3 times, 1 min 20 s" — how the candidate left the exam page, for teachers; null when they never did. */
    public function awayLabel(): ?string
    {
        if ($this->awayCount === 0) {
            return null;
        }
        $m = intdiv($this->awaySeconds, 60);
        $s = $this->awaySeconds % 60;
        $time = $m > 0 ? $m . ' min' . ($s > 0 ? ' ' . $s . ' s' : '') : $s . ' s';

        return ($this->awayCount === 1 ? 'Once' : $this->awayCount . ' times') . ', ' . $time;
    }

    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
    }

    public function isPractice(): bool
    {
        return $this->examId === null;
    }

    /** Seconds left at $now (UTC), never below zero. */
    public function secondsLeft(\DateTimeImmutable $now): int
    {
        $deadline = new \DateTimeImmutable($this->deadlineAt, new \DateTimeZone('UTC'));

        return max(0, $deadline->getTimestamp() - $now->getTimestamp());
    }

    /** Past the deadline and its grace, so nothing more may be saved. */
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        $deadline = new \DateTimeImmutable($this->deadlineAt, new \DateTimeZone('UTC'));

        return $now->getTimestamp() > $deadline->getTimestamp() + self::GRACE_SECONDS;
    }

    /** @return list<int> every question id in paper order */
    public function questionIds(): array
    {
        $ids = [];
        foreach ($this->paper['sections'] as $s) {
            foreach ($s['questions'] as $q) {
                $ids[] = $q['id'];
            }
        }

        return $ids;
    }

    /** @return array<int, list<int>> question id => option order */
    public function perms(): array
    {
        $out = [];
        foreach ($this->paper['sections'] as $s) {
            foreach ($s['questions'] as $q) {
                $out[$q['id']] = $q['perm'];
            }
        }

        return $out;
    }

    /** Minutes the candidate took (from start to submission or deadline). */
    public function minutesUsed(): int
    {
        $start = new \DateTimeImmutable($this->startedAt, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($this->submittedAt ?? $this->deadlineAt, new \DateTimeZone('UTC'));

        return max(0, (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 60));
    }
}
