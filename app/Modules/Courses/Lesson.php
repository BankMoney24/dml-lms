<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\View\Markdown;

final class Lesson
{
    public const TYPE_TEXT = 'text';
    public const TYPE_QUIZ = 'quiz';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_LAB = 'lab';

    /** type => [label, one-line description for authors] */
    public const TYPES = [
        self::TYPE_TEXT => ['Reading', 'Text with headings, lists and code, then mark complete'],
        self::TYPE_VIDEO => ['Video', 'A video you upload, or a YouTube or Vimeo link, with notes; loads only when the learner presses play'],
        self::TYPE_AUDIO => ['Audio', 'A recording you upload (a talk, a podcast, a language exercise) with notes; light on data'],
        self::TYPE_QUIZ => ['Quiz', 'Multiple choice, marked on the server, with explanations'],
        self::TYPE_ASSIGNMENT => ['Assignment', 'Learners submit work; a teacher approves it or asks for changes'],
        self::TYPE_LAB => ['Lab', 'A hands-on simulation from the lab catalogue, marked automatically'],
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
        public readonly ?string $labKey = null,
        public readonly bool $preview = false,
        public readonly ?int $mediaFileId = null,
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
            isset($row['lab_key']) && $row['lab_key'] !== '' ? (string) $row['lab_key'] : null,
            (bool) ($row['is_preview'] ?? false),
            isset($row['media_file_id']) ? (int) $row['media_file_id'] : null,
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

    public function isAudio(): bool
    {
        return $this->type === self::TYPE_AUDIO;
    }

    /** Video and audio lessons can carry an uploaded media file. */
    public function takesMedia(): bool
    {
        return $this->isVideo() || $this->isAudio();
    }

    /** Ready for learners: a video with a link or an upload, an audio lesson with its recording. */
    public function hasMedia(): bool
    {
        return $this->mediaFileId !== null || ($this->isVideo() && $this->video() !== null);
    }

    public function isLab(): bool
    {
        return $this->type === self::TYPE_LAB;
    }

    public function isAssignment(): bool
    {
        return $this->type === self::TYPE_ASSIGNMENT;
    }

    /** Readings, videos and audio are completed by the learner; quizzes, assignments and labs by an outcome. */
    public function learnerCompletes(): bool
    {
        return in_array($this->type, [self::TYPE_TEXT, self::TYPE_VIDEO, self::TYPE_AUDIO], true);
    }

    /** Readings, videos and audio can be opened by anyone as a free preview, if the author allows it (CRS-003). */
    public function canBePreview(): bool
    {
        return $this->learnerCompletes();
    }

    public function isOpenPreview(): bool
    {
        return $this->preview && $this->canBePreview();
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
