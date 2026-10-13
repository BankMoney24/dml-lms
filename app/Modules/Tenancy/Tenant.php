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
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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

    /** Changes when the logo does, so browsers may cache /logo for a long time. */
    public function logoVersion(): string
    {
        return substr(hash('sha256', (string) $this->logoKey), 0, 10);
    }
}
