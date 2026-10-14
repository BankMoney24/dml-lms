<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

final class Tenant
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /** Institution types offered by the setup wizard (PRD §6.4 step 2). */
    public const KINDS = [
        'school' => 'School',
        'university' => 'University',
        'polytechnic' => 'Polytechnic',
        'training_centre' => 'Training centre',
        'other' => 'Other',
    ];

    /** How the academic year is organised. */
    public const CALENDARS = [
        'terms' => 'Terms',
        'semesters' => 'Semesters',
        'flexible' => 'No fixed periods',
    ];

    /** What the school calls a group of learners: key => [singular, plural]. */
    public const GROUP_TERMS = [
        'class' => ['class', 'classes'],
        'cohort' => ['cohort', 'cohorts'],
        'group' => ['group', 'groups'],
        'stream' => ['stream', 'streams'],
        'set' => ['set', 'sets'],
    ];

    /** The usual word for each kind of institution, when the school has not chosen one. */
    private const DEFAULT_GROUP_TERMS = ['school' => 'class', 'training_centre' => 'cohort'];

    /**
     * @param list<string> $levels classes, years or levels, in order
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $status,
        public readonly string $timezone,
        public readonly string $locale,
        public readonly ?string $kind = null,
        public readonly string $tagline = '',
        public readonly string $theme = SchoolTheme::DEFAULT,
        public readonly ?string $logoKey = null,
        public readonly ?string $logoType = null,
        public readonly string $contactEmail = '',
        public readonly string $contactPhone = '',
        public readonly string $website = '',
        public readonly ?string $calendar = null,
        public readonly array $levels = [],
        public readonly bool $certificatesEnabled = true,
        public readonly bool $publicCatalogue = true,
        public readonly ?string $setupCompletedAt = null,
        public readonly ?string $groupTerm = null,
        /** @var array<string, string> network => https URL of a profile the school controls */
        public readonly array $socialLinks = [],
        /** @var array<string, string> search engine => site verification code */
        public readonly array $searchVerification = [],
        /** closed (staff add people), code (join with the school's code) or open (anyone may sign up) */
        public readonly string $registration = 'closed',
        public readonly ?string $joinCode = null,
        public readonly bool $parentRequests = true,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $levels = [];
        if (isset($row['levels']) && is_string($row['levels']) && $row['levels'] !== '') {
            $decoded = json_decode($row['levels'], true);
            if (is_array($decoded)) {
                $levels = array_values(array_filter($decoded, 'is_string'));
            }
        }

        return new self(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['status'],
            (string) $row['timezone'],
            (string) $row['locale'],
            isset($row['kind']) ? (string) $row['kind'] : null,
            (string) ($row['tagline'] ?? ''),
            SchoolTheme::exists((string) ($row['theme'] ?? '')) ? (string) $row['theme'] : SchoolTheme::DEFAULT,
            isset($row['logo_key']) ? (string) $row['logo_key'] : null,
            isset($row['logo_type']) ? (string) $row['logo_type'] : null,
            (string) ($row['contact_email'] ?? ''),
            (string) ($row['contact_phone'] ?? ''),
            (string) ($row['website'] ?? ''),
            isset($row['calendar']) ? (string) $row['calendar'] : null,
            $levels,
            (bool) ($row['certificates_enabled'] ?? true),
            (bool) ($row['public_catalogue'] ?? true),
            isset($row['setup_completed_at']) ? (string) $row['setup_completed_at'] : null,
            isset($row['group_term']) && isset(self::GROUP_TERMS[(string) $row['group_term']]) ? (string) $row['group_term'] : null,
            self::stringMap($row['social_links'] ?? null),
            self::stringMap($row['search_verification'] ?? null),
            in_array($row['registration'] ?? '', ['closed', 'code', 'open'], true) ? (string) $row['registration'] : 'closed',
            isset($row['join_code']) && $row['join_code'] !== '' ? (string) $row['join_code'] : null,
            (bool) ($row['parent_requests'] ?? true),
        );
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $val) {
            if (is_string($k) && is_string($val) && $val !== '') {
                $out[$k] = $val;
            }
        }

        return $out;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Whether people can create their own learner account here (ADR 0020). */
    public function signUpOpen(): bool
    {
        return $this->registration === 'open' || ($this->registration === 'code' && $this->joinCode !== null);
    }

    public function isSetUp(): bool
    {
        return $this->setupCompletedAt !== null;
    }

    public function hasLogo(): bool
    {
        return $this->logoKey !== null && $this->logoType !== null;
    }

    public function kindLabel(): ?string
    {
        return $this->kind === null ? null : (self::KINDS[$this->kind] ?? null);
    }

    public function calendarLabel(): ?string
    {
        return $this->calendar === null ? null : (self::CALENDARS[$this->calendar] ?? null);
    }

    /** The chosen word for a group of learners, or the usual one for this kind of institution. */
    public function groupTermKey(): string
    {
        return $this->groupTerm ?? (self::DEFAULT_GROUP_TERMS[(string) $this->kind] ?? 'group');
    }

    /** "class", or "Class" with $capital; "classes" with $plural. */
    public function groupWord(bool $plural = false, bool $capital = false): string
    {
        $word = self::GROUP_TERMS[$this->groupTermKey()][$plural ? 1 : 0];

        return $capital ? ucfirst($word) : $word;
    }

    /** Changes when the logo does, so browsers may cache /logo for a long time. */
    public function logoVersion(): string
    {
        return substr(hash('sha256', (string) $this->logoKey), 0, 10);
    }
}
