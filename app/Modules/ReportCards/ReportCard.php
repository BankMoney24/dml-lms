<?php

declare(strict_types=1);

namespace App\Modules\ReportCards;

/** One class's report card for a term (ADR 0046). */
final class ReportCard
{
    /** @param list<array{exam: int, label: string, weight: int}> $components */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $groupId,
        public readonly string $title,
        public readonly array $components,
        public readonly string $grading,
        public readonly string $nextTerm,
        public readonly string $status,
        public readonly ?string $publishedAt,
        public readonly int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $groupName = '',
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        $raw = json_decode((string) $r['components'], true);
        $components = [];
        foreach (is_array($raw) ? $raw : [] as $c) {
            if (is_array($c) && isset($c['exam'])) {
                $components[] = ['exam' => (int) $c['exam'], 'label' => (string) ($c['label'] ?? ''), 'weight' => (int) ($c['weight'] ?? 0)];
            }
        }

        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (int) $r['group_id'],
            (string) $r['title'],
            $components,
            (string) $r['grading'],
            (string) ($r['next_term'] ?? ''),
            (string) $r['status'],
            isset($r['published_at']) ? (string) $r['published_at'] : null,
            (int) $r['created_by'],
            (string) $r['created_at'],
            (string) $r['updated_at'],
            (string) ($r['group_name'] ?? ''),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
