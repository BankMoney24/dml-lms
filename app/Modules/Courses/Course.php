<?php

declare(strict_types=1);

namespace App\Modules\Courses;

final class Course
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    /** Formal curriculum subjects (e.g. SS1–SS3) or vocational and professional skills. */
    /** school: a curriculum subject; skills: vocational or professional; everyday: informal learning for life and work (ADR 0033). */
    public const TRACKS = ['school' => 'School subject', 'skills' => 'Skills', 'everyday' => 'Everyday learning'];

    public const ACCESS_MEMBERS = 'members';
    public const ACCESS_OPEN = 'open';

    public const ENROL_OPEN = 'open';
    public const ENROL_APPLY = 'apply';

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
        /** "members" (join to read) or "open" (anyone may read its readings and videos; ADR 0021). */
        public readonly string $access = self::ACCESS_MEMBERS,
        /** "open" (join or pay directly) or "apply" (apply and wait to be added; ADR 0025). */
        public readonly string $enrolment = self::ENROL_OPEN,
        /** Most learners at once; when full, new people join the waiting list. */
        public readonly ?int $capacity = null,
        /** Besides every required lesson: the lowest average (percent) across required quizzes; null for none (ADR 0032). */
        public readonly ?int $completionMinAverage = null,
        /** How many monthly parts learners may pay the price in (2 to 4), or null for in full only (ADR 0037). */
        public readonly ?int $instalments = null,
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
            ($row['access'] ?? '') === self::ACCESS_OPEN ? self::ACCESS_OPEN : self::ACCESS_MEMBERS,
            ($row['enrolment'] ?? '') === self::ENROL_APPLY ? self::ENROL_APPLY : self::ENROL_OPEN,
            isset($row['capacity']) && (int) $row['capacity'] > 0 ? (int) $row['capacity'] : null,
            isset($row['completion_min_average']) && (int) $row['completion_min_average'] > 0 ? min(100, (int) $row['completion_min_average']) : null,
            isset($row['instalments']) && (int) $row['instalments'] >= 2 && (int) $row['instalments'] <= 4 ? (int) $row['instalments'] : null,
        );
    }

    /**
     * An open course: free, and anyone (even without an account) may read its
     * readings and videos. A price always wins, so a paid course is never open.
     */
    public function isOpen(): bool
    {
        return $this->access === self::ACCESS_OPEN && !$this->isPaid();
    }

    public function takesApplications(): bool
    {
        return $this->enrolment === self::ENROL_APPLY;
    }

    /** Whether someone who is not enrolled may read this lesson (an open course, or a lesson the author made a free preview). */
    public function lessonIsReadable(Lesson $lesson): bool
    {
        return $lesson->isOpenPreview() || ($this->isOpen() && $lesson->canBePreview());
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

    /** Learners may pay this course in monthly parts (ADR 0037). */
    public function offersInstalments(): bool
    {
        return $this->isPaid() && $this->instalments !== null;
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
