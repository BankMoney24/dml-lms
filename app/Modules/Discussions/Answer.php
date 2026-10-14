<?php

declare(strict_types=1);

namespace App\Modules\Discussions;

/** A reply to a lesson question (ADR 0022). */
final class Answer
{
    public const VISIBLE = 'visible';
    public const HIDDEN = 'hidden';
    public const DELETED = 'deleted';

    public function __construct(
        public readonly int $id,
        public readonly int $questionId,
        public readonly int $userId,
        public readonly string $authorName,
        public readonly string $body,
        public readonly bool $byStaff,
        public readonly string $status,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['question_id'],
            (int) $row['user_id'],
            (string) ($row['author_name'] ?? ''),
            (string) $row['body'],
            (bool) $row['by_staff'],
            (string) $row['status'],
            (string) $row['created_at'],
        );
    }

    public function isVisible(): bool
    {
        return $this->status === self::VISIBLE;
    }
}
