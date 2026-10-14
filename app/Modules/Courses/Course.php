<?php

declare(strict_types=1);

namespace App\Modules\Courses;

final class Course
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    /** Formal curriculum subjects (e.g. SS1–SS3) or vocational and professional skills. */
    public const TRACKS = ['school' => 'School subject', 'skills' => 'Skills'];

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
        public readonly ?string $track = null,
        public readonly ?int $priceMinor = null,
        public readonly ?string $currency = null,
        /** Last change to the course or its lessons (UTC), for sitemaps and share images. */
        public readonly string $updatedAt = '',
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
            isset($row['track']) && isset(self::TRACKS[(string) $row['track']]) ? (string) $row['track'] : null,
            isset($row['price_minor']) && (int) $row['price_minor'] > 0 ? (int) $row['price_minor'] : null,
            isset($row['currency']) && is_string($row['currency']) && $row['currency'] !== '' ? $row['currency'] : null,
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** A course with a price; learners pay before they can start it. */
    public function isPaid(): bool
    {
        return $this->priceMinor !== null && $this->priceMinor > 0 && $this->currency !== null;
    }

    /** "₦15,000" or null for a free course. */
    public function priceLabel(): ?string
    {
        return $this->isPaid() ? \App\Modules\Payments\Money::format((int) $this->priceMinor, (string) $this->currency) : null;
    }

    public function trackLabel(): ?string
    {
        return $this->track === null ? null : self::TRACKS[$this->track];
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
