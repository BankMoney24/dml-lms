<?php

declare(strict_types=1);

namespace App\Modules\Joining;

final class JoinRequest
{
    public const TEACH = 'teach';
    public const GUARDIAN = 'guardian';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $kind,
        public readonly ?int $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $childName,
        public readonly string $message,
        public readonly string $status,
        public readonly ?string $decidedBy,
        public readonly ?string $decidedAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['kind'],
            isset($row['user_id']) ? (int) $row['user_id'] : null,
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['phone'],
            (string) $row['child_name'],
            (string) ($row['message'] ?? ''),
            (string) $row['status'],
            isset($row['decided_by_name']) ? (string) $row['decided_by_name'] : null,
            isset($row['decided_at']) ? (string) $row['decided_at'] : null,
            (string) $row['created_at'],
        );
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function kindLabel(): string
    {
        return $this->kind === self::TEACH ? 'Wants to teach' : 'Parent or guardian';
    }
}
