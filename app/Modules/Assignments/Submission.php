<?php

declare(strict_types=1);

namespace App\Modules\Assignments;

final class Submission
{
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const CHANGES_REQUESTED = 'changes_requested';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $lessonId,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly string $body,
        public readonly ?string $linkUrl,
        public readonly string $status,
        public readonly ?string $feedback,
        public readonly ?int $reviewedBy,
        public readonly ?string $reviewedAt,
        public readonly string $submittedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['lesson_id'],
            (int) $row['enrolment_id'],
            (int) $row['user_id'],
            (string) $row['body'],
            $row['link_url'] === null ? null : (string) $row['link_url'],
            (string) $row['status'],
            $row['feedback'] === null ? null : (string) $row['feedback'],
            $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
            (string) $row['submitted_at'],
        );
    }

    public function isPending(): bool
    {
        return $this->status === self::SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Approved',
            self::CHANGES_REQUESTED => 'Changes requested',
            default => 'Waiting for review',
        };
    }

    /** Badge tone used by the shared badge helper. */
    public function tone(): string
    {
        return match ($this->status) {
            self::APPROVED => 'done',
            self::CHANGES_REQUESTED => 'danger',
            default => 'draft',
        };
    }
}
