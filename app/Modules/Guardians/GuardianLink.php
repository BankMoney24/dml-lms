<?php

declare(strict_types=1);

namespace App\Modules\Guardians;

final class GuardianLink
{
    public const RELATIONSHIPS = [
        'mother' => 'Mother',
        'father' => 'Father',
        'guardian' => 'Guardian',
        'grandparent' => 'Grandparent',
        'sponsor' => 'Sponsor',
        'other' => 'Other',
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $guardianId,
        public readonly int $learnerId,
        public readonly string $relationship,
        public readonly bool $canProgress,
        public readonly bool $canResults,
        public readonly bool $canNotices,
        public readonly string $status,
        public readonly string $verifiedAt,
        public readonly string $guardianName = '',
        public readonly string $guardianEmail = '',
        public readonly string $learnerName = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['guardian_id'],
            (int) $row['learner_id'],
            (string) $row['relationship'],
            (bool) $row['can_progress'],
            (bool) $row['can_results'],
            (bool) $row['can_notices'],
            (string) $row['status'],
            (string) $row['verified_at'],
            (string) ($row['guardian_name'] ?? ''),
            (string) ($row['guardian_email'] ?? ''),
            (string) ($row['learner_name'] ?? ''),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function relationshipLabel(): string
    {
        return self::RELATIONSHIPS[$this->relationship] ?? 'Guardian';
    }
}
