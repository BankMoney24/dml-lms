<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

final class Tenant
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $status,
        public readonly string $timezone,
        public readonly string $locale,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['status'],
            (string) $row['timezone'],
            (string) $row['locale'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
