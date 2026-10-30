<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

/** A passage, table or instructions that several questions in a bank share. */
final class Passage
{
    public function __construct(
        public readonly int $id,
        public readonly int $bankId,
        public readonly string $title,
        public readonly string $body,
        public readonly int $questionCount = 0,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self((int) $r['id'], (int) $r['bank_id'], (string) $r['title'], (string) $r['body'], (int) ($r['question_count'] ?? 0));
    }
}
