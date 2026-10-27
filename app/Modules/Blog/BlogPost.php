<?php

declare(strict_types=1);

namespace App\Modules\Blog;

/** A post on the school's blog (ADR 0034). */
final class BlogPost
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    /** key => [label, plural heading] */
    public const CATEGORIES = [
        'news' => ['News', 'News'],
        'events' => ['Event', 'Events'],
        'stories' => ['Story', 'Stories'],
        'guides' => ['Guide', 'Guides'],
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $body,
        public readonly string $category,
        public readonly string $status,
        public readonly int $authorId,
        public readonly string $authorName,
        public readonly ?string $publishedAt,
        public readonly string $createdAt,
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
            isset(self::CATEGORIES[(string) $row['category']]) ? (string) $row['category'] : 'news',
            (string) $row['status'],
            (int) $row['author_id'],
            (string) ($row['author_name'] ?? ''),
            isset($row['published_at']) ? (string) $row['published_at'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function path(): string
    {
        return '/blog/' . $this->slug;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0];
    }

    /** About 200 words a minute, at least one. */
    public function readingMinutes(): int
    {
        return max(1, (int) round(str_word_count(strip_tags($this->body)) / 200));
    }
}
