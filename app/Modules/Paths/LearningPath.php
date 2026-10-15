<?php

declare(strict_types=1);

namespace App\Modules\Paths;

/** Courses in a recommended order, e.g. "Junior web developer" (ADR 0023). */
final class LearningPath
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $level,
        public readonly string $status,
        public readonly string $updatedAt,
        /** Filled by lists: how many courses the path has. */
        public readonly int $courseCount = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $level = isset($row['level']) ? (string) $row['level'] : null;

        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['slug'],
            (string) $row['title'],
            (string) $row['summary'],
            $level !== null && isset(\App\Modules\Courses\Course::LEVELS[$level]) ? $level : null,
            (string) $row['status'],
            (string) $row['updated_at'],
            (int) ($row['course_count'] ?? 0),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function levelLabel(): ?string
    {
        return $this->level === null ? null : \App\Modules\Courses\Course::LEVELS[$this->level];
    }
}
