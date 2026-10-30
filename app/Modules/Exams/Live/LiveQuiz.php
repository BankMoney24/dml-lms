<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

/** A quick quiz a teacher runs live in class (ADR 0042). */
final class LiveQuiz
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $title,
        public readonly int $createdBy,
        public readonly string $creatorName,
        public readonly int $questionCount,
        public readonly int $runs,
        public readonly string $updatedAt,
        public readonly ?string $archivedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (string) $r['title'],
            (int) $r['created_by'],
            (string) ($r['creator_name'] ?? ''),
            (int) ($r['question_count'] ?? 0),
            (int) ($r['runs'] ?? 0),
            (string) $r['updated_at'],
            isset($r['archived_at']) ? (string) $r['archived_at'] : null,
        );
    }
}
