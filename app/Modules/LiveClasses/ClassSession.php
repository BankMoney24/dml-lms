<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses;

use DateTimeImmutable;
use DateTimeZone;

/** A lesson taught live to one class or cohort, online, in a room, or both (ADR 0028). */
final class ClassSession
{
    public const MODES = ['online' => 'Online', 'room' => 'In a room', 'both' => 'Room and online'];
    public const JOIN_EARLY_MINUTES = 15;
    public const LATE_AFTER_MINUTES = 10;

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $groupId,
        public readonly ?int $courseId,
        public readonly ?string $seriesId,
        public readonly string $title,
        public readonly string $description,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $mode,
        public readonly string $location,
        public readonly ?string $joinUrl,
        public readonly ?string $recordingUrl,
        public readonly string $status,
        public readonly ?string $cancelledReason,
        public readonly string $groupName = '',
        public readonly string $courseTitle = '',
        public readonly string $courseSlug = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['group_id'],
            isset($row['course_id']) ? (int) $row['course_id'] : null,
            isset($row['series_id']) ? (string) $row['series_id'] : null,
            (string) $row['title'],
            (string) ($row['description'] ?? ''),
            (string) $row['starts_at'],
            (string) $row['ends_at'],
            (string) $row['mode'],
            (string) $row['location'],
            isset($row['join_url']) ? (string) $row['join_url'] : null,
            isset($row['recording_url']) ? (string) $row['recording_url'] : null,
            (string) $row['status'],
            isset($row['cancelled_reason']) ? (string) $row['cancelled_reason'] : null,
            (string) ($row['group_name'] ?? ''),
            (string) ($row['course_title'] ?? ''),
            (string) ($row['course_slug'] ?? ''),
        );
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isOnline(): bool
    {
        return $this->mode !== 'room' && $this->joinUrl !== null;
    }

    public function inRoom(): bool
    {
        return $this->mode !== 'online';
    }

    public function minutes(): int
    {
        return (int) round(($this->ts($this->endsAt) - $this->ts($this->startsAt)) / 60);
    }

    /** before | open (joinable) | live | ended | cancelled, at a moment in time. */
    public function phase(int $now): string
    {
        if ($this->isCancelled()) {
            return 'cancelled';
        }
        $start = $this->ts($this->startsAt);
        if ($now >= $this->ts($this->endsAt)) {
            return 'ended';
        }
        if ($now >= $start) {
            return 'live';
        }

        return $now >= $start - self::JOIN_EARLY_MINUTES * 60 ? 'open' : 'before';
    }

    public function hasEnded(int $now): bool
    {
        return $now >= $this->ts($this->endsAt);
    }

    public function isLate(int $now): bool
    {
        return $now > $this->ts($this->startsAt) + self::LATE_AFTER_MINUTES * 60;
    }

    /**
     * The start in the school's time zone, for forms.
     *
     * @return array{0: string, 1: string} [Y-m-d, H:i]
     */
    public function localStart(string $zone): array
    {
        $t = (new DateTimeImmutable($this->startsAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));

        return [$t->format('Y-m-d'), $t->format('H:i')];
    }

    private function ts(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }
}
