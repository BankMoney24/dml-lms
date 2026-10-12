<?php

declare(strict_types=1);

namespace App\Modules\Courses;

final class Lesson
{
    public const TYPE_TEXT = 'text';
    public const TYPE_QUIZ = 'quiz';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $courseId,
        public readonly int $moduleId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly int $position,
        public readonly bool $mandatory,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['course_id'],
            (int) $row['module_id'],
            (string) $row['type'],
            (string) $row['title'],
            (string) $row['body'],
            (int) $row['position'],
            (bool) $row['is_mandatory'],
        );
    }

    public function isQuiz(): bool
    {
        return $this->type === self::TYPE_QUIZ;
    }
}
