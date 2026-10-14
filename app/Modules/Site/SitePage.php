<?php

declare(strict_types=1);

namespace App\Modules\Site;

final class SitePage
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $body,
        public readonly string $status,
        public readonly ?string $publishedAt,
        public readonly string $updatedAt,
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
            (string) $row['body'],
            (string) $row['status'],
            isset($row['published_at']) ? (string) $row['published_at'] : null,
            (string) $row['updated_at'],
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function path(): string
    {
        return '/' . $this->slug;
    }
}
