<?php

declare(strict_types=1);

namespace App\Modules\Courses;

final class Course
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    /** @param list<string> $outcomes */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $status,
        public readonly ?string $level = null,
        public readonly ?int $durationHours = null,
        public readonly array $outcomes = [],
        public readonly string $prerequisites = '',
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
            (string) $row['status'],
            $level !== null && isset(self::LEVELS[$level]) ? $level : null,
            isset($row['duration_hours']) ? (int) $row['duration_hours'] : null,
            self::lines((string) ($row['outcomes'] ?? '')),
            (string) ($row['prerequisites'] ?? ''),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function levelLabel(): ?string
    {
        return $this->level === null ? null : self::LEVELS[$this->level];
    }

    /** @return list<string> non-empty trimmed lines, list markers removed */
    public static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
