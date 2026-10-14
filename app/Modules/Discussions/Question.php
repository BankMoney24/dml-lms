<?php

declare(strict_types=1);

namespace App\Modules\Discussions;

/** A question someone taking a course asked under a lesson (ADR 0022). */
final class Question
{
    public const OPEN = 'open';
    public const HIDDEN = 'hidden';
    public const DELETED = 'deleted';

    /** @param list<Answer> $answers */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $courseId,
        public readonly int $lessonId,
        public readonly int $userId,
        public readonly string $authorName,
        public readonly string $body,
        public readonly string $status,
        public readonly ?int $acceptedAnswerId,
        public readonly int $answerCount,
        public readonly bool $staffAnswered,
        public readonly string $createdAt,
        public readonly string $lastActivityAt,
        public readonly array $answers = [],
        /** Filled for lists across lessons (the teachers' inbox). */
        public readonly string $courseTitle = '',
        public readonly string $courseSlug = '',
        public readonly string $lessonTitle = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['course_id'],
            (int) $row['lesson_id'],
            (int) $row['user_id'],
            (string) ($row['author_name'] ?? ''),
            (string) $row['body'],
            (string) $row['status'],
            isset($row['accepted_answer_id']) ? (int) $row['accepted_answer_id'] : null,
            (int) $row['answer_count'],
            (bool) $row['staff_answered'],
            (string) $row['created_at'],
            (string) $row['last_activity_at'],
            [],
            (string) ($row['course_title'] ?? ''),
            (string) ($row['course_slug'] ?? ''),
            (string) ($row['lesson_title'] ?? ''),
        );
    }

    /** @param list<Answer> $answers */
    public function withAnswers(array $answers): self
    {
        return new self(
            $this->id,
            $this->tenantId,
            $this->courseId,
            $this->lessonId,
            $this->userId,
            $this->authorName,
            $this->body,
            $this->status,
            $this->acceptedAnswerId,
            $this->answerCount,
            $this->staffAnswered,
            $this->createdAt,
            $this->lastActivityAt,
            $answers,
            $this->courseTitle,
            $this->courseSlug,
            $this->lessonTitle,
        );
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isAnswered(): bool
    {
        return $this->acceptedAnswerId !== null || $this->staffAnswered;
    }

    public function lessonUrl(): string
    {
        return '/courses/' . rawurlencode($this->courseSlug) . '/lessons/' . $this->lessonId . '#q' . $this->id;
    }
}
