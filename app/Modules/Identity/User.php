<?php

declare(strict_types=1);

namespace App\Modules\Identity;

final class User
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $status,
        public readonly bool $isPlatformAdmin,
        public readonly int $sessionVersion,
        public readonly bool $mustChangePassword = false,
        public readonly bool $twoFactorEnabled = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['status'],
            (bool) $row['is_platform_admin'],
            (int) $row['session_version'],
            (bool) ($row['must_change_password'] ?? false),
            ($row['totp_enabled_at'] ?? null) !== null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
