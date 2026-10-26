<?php

declare(strict_types=1);

namespace App\Modules\Learning;

final class Enrolment
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    /** Removed from the course (for example after a refund): no access, history kept (ADR 0032). */
    public const STATUS_WITHDRAWN = 'withdrawn';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $courseId,
        public readonly int $userId,
        public readonly string $status,
        public readonly ?string $completedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['course_id'],
            (int) $row['user_id'],
            (string) $row['status'],
            $row['completed_at'] === null ? null : (string) $row['completed_at'],
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
