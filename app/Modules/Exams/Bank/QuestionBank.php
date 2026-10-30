<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

/** A school's questions for one subject (ADR 0042). */
final class QuestionBank
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $practice,
        public readonly int $activeCount,
        public readonly int $retiredCount,
        public readonly ?string $archivedAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (string) $r['name'],
            (string) $r['description'],
            (bool) $r['practice'],
            (int) ($r['active_count'] ?? 0),
            (int) ($r['retired_count'] ?? 0),
            isset($r['archived_at']) ? (string) $r['archived_at'] : null,
            (string) $r['updated_at'],
        );
    }
}
