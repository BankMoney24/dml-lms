<?php

declare(strict_types=1);

namespace App\Modules\Groups;

/** A class, cohort or group of learners; the school chooses the word (Tenant::groupWord). */
final class LearnerGroup
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly ?string $level,
        public readonly ?string $periodLabel,
        public readonly ?string $archivedAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['name'],
            isset($row['level']) && $row['level'] !== '' ? (string) $row['level'] : null,
            isset($row['period_label']) && $row['period_label'] !== '' ? (string) $row['period_label'] : null,
            isset($row['archived_at']) ? (string) $row['archived_at'] : null,
            (string) $row['created_at'],
        );
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** "SS 2, 2026/2027" or null. */
    public function detailLine(): ?string
    {
        $parts = array_values(array_filter([$this->level, $this->periodLabel], static fn (?string $p): bool => $p !== null));

        return $parts === [] ? null : implode(', ', $parts);
    }
}
