<?php

declare(strict_types=1);

namespace App\Modules\Identity;

final class User
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /** How pages look for this person: the device's setting, or always light or dark. */
    public const APPEARANCES = ['system' => 'Match this device', 'light' => 'Light', 'dark' => 'Dark'];

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $status,
        public readonly bool $isPlatformAdmin,
        public readonly int $sessionVersion,
        public readonly bool $mustChangePassword = false,
        public readonly bool $twoFactorEnabled = false,
        public readonly string $appearance = 'system',
        public readonly bool $emailVerified = true,
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
            isset(self::APPEARANCES[(string) ($row['appearance'] ?? '')]) ? (string) $row['appearance'] : 'system',
            !array_key_exists('email_verified_at', $row) || $row['email_verified_at'] !== null,
        );
    }

    /** Two letters for the avatar: first and last name, or the first two letters of a single name. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->displayName)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return '?';
        }
        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : mb_substr($parts[0], 1, 1);

        return mb_strtoupper($first . $last);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
