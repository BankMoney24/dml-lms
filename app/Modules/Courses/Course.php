<?php

declare(strict_types=1);

namespace App\Modules\Courses;

final class Course
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['slug'],
            (string) $row['title'],
            (string) $row['summary'],
            (string) $row['status'],
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
