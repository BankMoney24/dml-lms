<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\View\Markdown;

final class Lesson
{
    public const TYPE_TEXT = 'text';
    public const TYPE_QUIZ = 'quiz';
    public const TYPE_VIDEO = 'video';
    public const TYPE_ASSIGNMENT = 'assignment';

    /** type => [label, one-line description for authors] */
    public const TYPES = [
        self::TYPE_TEXT => ['Reading', 'Text with headings, lists and code, then mark complete'],
        self::TYPE_VIDEO => ['Video', 'A YouTube or Vimeo video with notes; loads only when the learner chooses'],
        self::TYPE_QUIZ => ['Quiz', 'Multiple choice, marked on the server, with explanations'],
        self::TYPE_ASSIGNMENT => ['Assignment', 'Learners submit work; a teacher approves it or asks for changes'],
    ];

    private const WORDS_PER_MINUTE = 200;

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
        public readonly ?string $mediaUrl = null,
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
            isset($row['media_url']) && $row['media_url'] !== '' ? (string) $row['media_url'] : null,
        );
    }

    public function isQuiz(): bool
    {
        return $this->type === self::TYPE_QUIZ;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function isAssignment(): bool
    {
        return $this->type === self::TYPE_ASSIGNMENT;
    }

    /** Readings and videos are completed by the learner; quizzes and assignments by an outcome. */
    public function learnerCompletes(): bool
    {
        return $this->type === self::TYPE_TEXT || $this->type === self::TYPE_VIDEO;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst($this->type);
    }

    public function video(): ?VideoLink
    {
        return $this->mediaUrl === null ? null : VideoLink::parse($this->mediaUrl);
    }

    /** Estimated minutes to read the text, at least 1. */
    public function readingMinutes(): int
    {
        return max(1, (int) ceil(Markdown::wordCount($this->body) / self::WORDS_PER_MINUTE));
    }
}
