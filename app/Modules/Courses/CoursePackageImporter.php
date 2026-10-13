<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\Database\Connection;
use App\Core\Http\UploadedFile;
use App\Core\Validation\ValidationException;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Identity\CurrentUser;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Imports a course package: a folder with course.json and Markdown lesson
 * files. Everything goes through CourseAuthoring, so a package obeys the
 * same rules as a course built by hand, and the whole import is one
 * transaction: a package with a mistake leaves nothing behind.
 *
 * course.json:
 * {
 *   "title": "…", "summary": "…", "level": "advanced", "duration_hours": 40,
 *   "outcomes": ["…"], "prerequisites": "…",
 *   "modules": [{ "title": "…", "lessons": [
 *     { "type": "text", "title": "…", "file": "lessons/01-intro.md", "attachments": ["files/cheat-sheet.pdf"] },
 *     { "type": "video", "title": "…", "url": "https://www.youtube.com/watch?v=…", "file": "…" },
 *     { "type": "assignment", "title": "…", "file": "…" },
 *     { "type": "quiz", "title": "…", "intro": "…", "pass_threshold": 70, "max_attempts": 3,
 *       "questions": [{ "prompt": "…", "options": ["…", "…"], "correct": 0, "explanation": "…" }] }
 *   ]}]
 * }
 *
 * "attachments" (any lesson type) become lesson files, checked like uploads.
 */
final class CoursePackageImporter
{
    /** @var list<string> storage keys written during the current import, removed again if it fails */
    private array $storedKeys = [];

    public function __construct(
        private readonly Connection $db,
        private readonly CourseAuthoring $authoring,
        private readonly FileService $files,
        private readonly FileRepository $fileRecords,
        private readonly LessonRepository $lessons,
    ) {
    }

    /** @return array{course: Course, modules: int, lessons: int, questions: int, files: int} */
    public function import(int $tenantId, CurrentUser $author, string $directory, bool $publish): array
    {
        $package = $this->read($directory);

        return $this->guarded(fn (): array => $this->db->transaction(function () use ($tenantId, $author, $directory, $package, $publish): array {
            $course = $this->authoring->createCourse($tenantId, $author, self::str($package, 'title', 'course'), self::str($package, 'summary', 'course', ''));
            $this->step('course details', fn () => $this->authoring->updateDetails($course, $author, [
                'title' => $course->title,
                'summary' => $course->summary,
                'level' => self::str($package, 'level', 'course', ''),
                'duration_hours' => isset($package['duration_hours']) ? (string) self::int($package, 'duration_hours', 'course') : '',
                'outcomes' => implode("\n", self::strings($package, 'outcomes', 'course')),
                'prerequisites' => self::str($package, 'prerequisites', 'course', ''),
                'track' => self::str($package, 'track', 'course', ''),
            ]));

            $counts = ['modules' => 0, 'lessons' => 0, 'questions' => 0, 'files' => 0];
            foreach (self::list($package, 'modules', 'course') as $m => $module) {
                $where = sprintf('modules[%d]', $m);
                $moduleId = $this->step($where, fn (): int => $this->authoring->addModule($course, $author, self::str($module, 'title', $where)));
                $counts['modules']++;
                foreach (self::list($module, 'lessons', $where) as $l => $lesson) {
                    $at = sprintf('%s.lessons[%d]', $where, $l);
                    [$questions, $files] = $this->importLesson($course, $author, $moduleId, $lesson, $directory, $at);
                    $counts['questions'] += $questions;
                    $counts['files'] += $files;
                    $counts['lessons']++;
                }
            }

            if ($publish) {
                $this->step('publish', fn () => $this->authoring->publish($course, $author));
            }

            return ['course' => $course] + $counts;
        }));
    }

    /**
     * Adds the package's files to a course imported earlier (before packages
     * carried files, or after a teacher removed some). Lessons are matched by
     * title; a file already attached under the same name is skipped.
     *
     * @return int files added
     */
    public function syncFiles(Course $course, CurrentUser $author, string $directory): int
    {
        $package = $this->read($directory);
        $byTitle = [];
        foreach ($this->lessons->orderedLessons($course->tenantId, $course->id) as $lesson) {
            $byTitle[$lesson->title] ??= $lesson;
        }

        return $this->guarded(fn (): int => $this->db->transaction(function () use ($course, $author, $directory, $package, $byTitle): int {
            $added = 0;
            foreach (self::list($package, 'modules', 'course') as $m => $module) {
                foreach (self::list($module, 'lessons', sprintf('modules[%d]', $m)) as $l => $entry) {
                    $at = sprintf('modules[%d].lessons[%d]', $m, $l);
                    $lesson = $byTitle[self::str($entry, 'title', $at)] ?? null;
                    if ($lesson === null) {
                        continue;
                    }
                    $existing = array_map(static fn ($f): string => $f->originalName, $this->fileRecords->forLesson($course->tenantId, $lesson->id));
                    foreach (self::strings($entry, 'attachments', $at) as $relative) {
                        if (!in_array(basename($relative), $existing, true)) {
                            $this->attach($course->tenantId, $author, $lesson->id, $this->resolve($directory, $relative, $at), $at);
                            $added++;
                        }
                    }
                }
            }

            return $added;
        }));
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function guarded(callable $work): mixed
    {
        $this->storedKeys = [];
        try {
            return $work();
        } catch (Throwable $e) {
            // The transaction rolled back the rows; remove the copied files too.
            $this->files->deleteBlobs($this->storedKeys);
            throw $e;
        } finally {
            $this->storedKeys = [];
        }
    }

    private function attach(int $tenantId, CurrentUser $author, int $lessonId, string $path, string $at): void
    {
        $prepared = $this->step($at . ' (' . basename($path) . ')', fn (): array => $this->files->prepare($tenantId, [UploadedFile::fromLocalPath($path)]));
        foreach ($this->files->persist($tenantId, $author->user->id, $prepared, $lessonId, null) as $stored) {
            $this->storedKeys[] = $stored->storageKey;
        }
    }

    /**
     * @param array<mixed> $lesson
     * @return array{0: int, 1: int} questions and files added
     */
    private function importLesson(Course $course, CurrentUser $author, int $moduleId, array $lesson, string $directory, string $at): array
    {
        $type = self::str($lesson, 'type', $at);
        $body = isset($lesson['file'])
            ? (string) file_get_contents($this->resolve($directory, self::str($lesson, 'file', $at), $at))
            : self::str($lesson, in_array($type, ['quiz', 'lab'], true) ? 'intro' : 'body', $at, '');

        $lessonId = $this->step($at, fn (): int => $this->authoring->addLesson($course, $author, $moduleId, [
            'type' => $type,
            'title' => self::str($lesson, 'title', $at),
            'body' => $body,
            'media_url' => self::str($lesson, 'url', $at, ''),
            'pass_threshold' => (string) ($lesson['pass_threshold'] ?? 70),
            'max_attempts' => isset($lesson['max_attempts']) ? (string) $lesson['max_attempts'] : '',
            'lab_key' => self::str($lesson, 'lab', $at, ''),
        ]));

        $attachments = self::strings($lesson, 'attachments', $at);
        foreach ($attachments as $relative) {
            $this->attach($course->tenantId, $author, $lessonId, $this->resolve($directory, $relative, $at), $at);
        }

        if ($type !== Lesson::TYPE_QUIZ) {
            return [0, count($attachments)];
        }
        $questions = self::list($lesson, 'questions', $at);
        foreach ($questions as $q => $question) {
            $where = sprintf('%s.questions[%d]', $at, $q);
            $options = self::strings($question, 'options', $where);
            $this->step($where, fn (): int => $this->authoring->addQuestion(
                $course,
                $author,
                $lessonId,
                self::str($question, 'prompt', $where),
                $options,
                (string) self::int($question, 'correct', $where),
                self::str($question, 'explanation', $where, ''),
            ));
        }

        return [count($questions), count($attachments)];
    }

    /** @return array<mixed> */
    private function read(string $directory): array
    {
        $path = rtrim($directory, '/') . '/course.json';
        if (!is_file($path)) {
            throw new RuntimeException("No course.json in $directory.");
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('course.json is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new RuntimeException('course.json must contain an object.');
        }

        return $data;
    }

    /** Absolute path of a file inside the package; anything outside it is refused. */
    private function resolve(string $directory, string $relative, string $at): string
    {
        $base = realpath($directory);
        $full = realpath(rtrim($directory, '/') . '/' . $relative);
        // Lesson files must live inside the package.
        if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            throw new RuntimeException("$at: file \"$relative\" not found in the package.");
        }

        return $full;
    }

    /**
     * Runs one authoring step and turns validation errors into a message that
     * says where in the package the problem is.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function step(string $where, callable $work): mixed
    {
        try {
            return $work();
        } catch (ValidationException $e) {
            throw new RuntimeException($where . ': ' . implode(' ', $e->errors), 0, $e);
        }
    }

    /** @param array<mixed> $data */
    private static function str(array $data, string $key, string $where, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value)) {
            throw new RuntimeException("$where: \"$key\" must be text.");
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private static function int(array $data, string $key, string $where): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException("$where: \"$key\" must be a whole number.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function list(array $data, string $key, string $where): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("$where: \"$key\" must be a list.");
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new RuntimeException("$where: every entry in \"$key\" must be an object.");
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function strings(array $data, string $key, string $where): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("$where: \"$key\" must be a list of text.");
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException("$where: \"$key\" must be a list of text.");
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
