<?php

declare(strict_types=1);

namespace App\Modules\Support;

final class SupportRequest
{
    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const RESOLVED = 'resolved';

    public const CATEGORIES = [
        'account' => 'Signing in or my account',
        'course' => 'A course, lesson or lab',
        'payment' => 'A payment',
        'technical' => 'Something is not working',
        'other' => 'Something else',
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly string $category,
        public readonly string $subject,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $requesterName = '',
        public readonly string $requesterEmail = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (string) $row['category'],
            (string) $row['subject'],
            (string) $row['status'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (string) ($row['display_name'] ?? ''),
            (string) ($row['email'] ?? ''),
        );
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Something else';
    }

    /**
     * Label and badge tone; staff and the person who asked see different words.
     *
     * @return array{string, string}
     */
    public function statusFor(bool $staff): array
    {
        return match ($this->status) {
            self::ANSWERED => $staff ? ['Waiting for them', 'neutral'] : ['Answered', 'done'],
            self::RESOLVED => ['Resolved', 'ok'],
            default => $staff ? ['Needs an answer', 'draft'] : ['Waiting for the school', 'draft'],
        };
    }
}
