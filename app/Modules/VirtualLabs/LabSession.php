<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/** One launch of a lab by one learner, bound to their school, course, lesson and enrolment (LAB-006). */
final class LabSession
{
    public const OPEN = 'open';
    public const SUBMITTED = 'submitted';
    public const EXPIRED = 'expired';
    public const ENDED = 'ended';

    /**
     * @param array<string, mixed>                                    $evidence
     * @param list<array{label: string, ok: bool, detail: string}> $feedback
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $lessonId,
        public readonly int $enrolmentId,
        public readonly string $labKey,
        public readonly int $labVersion,
        public readonly string $seed,
        public readonly string $status,
        public readonly string $startedAt,
        public readonly string $expiresAt,
        public readonly ?string $endedAt,
        public readonly ?int $score,
        public readonly ?bool $passed,
        public readonly array $evidence,
        public readonly array $feedback,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $evidence = json_decode((string) ($row['evidence'] ?? ''), true);
        $feedback = json_decode((string) ($row['feedback'] ?? ''), true);
        $lines = [];
        foreach (is_array($feedback) ? $feedback : [] as $line) {
            if (is_array($line)) {
                $lines[] = ['label' => (string) ($line['label'] ?? ''), 'ok' => (bool) ($line['ok'] ?? false), 'detail' => (string) ($line['detail'] ?? '')];
            }
        }

        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (int) $row['lesson_id'],
            (int) $row['enrolment_id'],
            (string) $row['lab_key'],
            (int) $row['lab_version'],
            (string) $row['seed'],
            (string) $row['status'],
            (string) $row['started_at'],
            (string) $row['expires_at'],
            $row['ended_at'] === null ? null : (string) $row['ended_at'],
            $row['score'] === null ? null : (int) $row['score'],
            $row['passed'] === null ? null : (bool) $row['passed'],
            is_array($evidence) ? $evidence : [],
            $lines,
        );
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => 'In progress',
            self::SUBMITTED => $this->passed === true ? 'Passed' : 'Not passed yet',
            self::EXPIRED => 'Time ran out',
            default => 'Ended',
        };
    }

    public function secondsLeft(int $now): int
    {
        return max(0, (int) strtotime($this->expiresAt . ' UTC') - $now);
    }
}
