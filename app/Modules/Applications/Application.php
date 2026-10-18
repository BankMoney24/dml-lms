<?php

declare(strict_types=1);

namespace App\Modules\Applications;

/** Someone asking for a place on a course, waiting for the school (ADR 0025). */
final class Application
{
    public const WAITING = 'waiting';
    public const OFFERED = 'offered';
    public const ENROLLED = 'enrolled';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $courseId,
        public readonly ?int $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $message,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $decidedAt,
        public readonly string $courseTitle = '',
        public readonly string $courseSlug = '',
        public readonly ?string $decidedBy = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['course_id'],
            isset($row['user_id']) ? (int) $row['user_id'] : null,
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['phone'],
            (string) $row['message'],
            (string) $row['status'],
            (string) $row['created_at'],
            isset($row['decided_at']) ? (string) $row['decided_at'] : null,
            (string) ($row['course_title'] ?? ''),
            (string) ($row['course_slug'] ?? ''),
            isset($row['decider_name']) ? (string) $row['decider_name'] : null,
        );
    }

    /** Still in play: waiting for a decision, or offered a place not yet taken. */
    public function isActive(): bool
    {
        return $this->status === self::WAITING || $this->status === self::OFFERED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::WAITING => 'Waiting',
            self::OFFERED => 'Place offered',
            self::ENROLLED => 'Enrolled',
            self::DECLINED => 'Not offered a place',
            default => 'Withdrawn',
        };
    }
}
