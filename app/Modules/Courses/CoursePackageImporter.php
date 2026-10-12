<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\Database\Connection;
use App\Core\Validation\ValidationException;
use App\Modules\Identity\CurrentUser;
use JsonException;
use RuntimeException;

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
 *     { "type": "text", "title": "…", "file": "lessons/01-intro.md" },
 *     { "type": "video", "title": "…", "url": "https://www.youtube.com/watch?v=…", "file": "…" },
 *     { "type": "assignment", "title": "…", "file": "…" },
 *     { "type": "quiz", "title": "…", "intro": "…", "pass_threshold": 70, "max_attempts": 3,
 *       "questions": [{ "prompt": "…", "options": ["…", "…"], "correct": 0, "explanation": "…" }] }
 *   ]}]
 * }
 */
final class CoursePackageImporter
{
    public function __construct(private readonly Connection $db, private readonly CourseAuthoring $authoring)
    {
    }

    /** @return array{course: Course, modules: int, lessons: int, questions: int} */
    public function import(int $tenantId, CurrentUser $author, string $directory, bool $publish): array
    {
        $package = $this->read($directory);

        return $this->db->transaction(function () use ($tenantId, $author, $directory, $package, $publish): array {
            $course = $this->authoring->createCourse($tenantId, $author, self::str($package, 'title', 'course'), self::str($package, 'summary', 'course', ''));
            $this->step('course details', fn () => $this->authoring->updateDetails($course, $author, [
                'title' => $course->title,
                'summary' => $course->summary,
                'level' => self::str($package, 'level', 'course', ''),
                'duration_hours' => isset($package['duration_hours']) ? (string) self::int($package, 'duration_hours', 'course') : '',
                'outcomes' => implode("\n", self::strings($package, 'outcomes', 'course')),
                'prerequisites' => self::str($package, 'prerequisites', 'course', ''),
            ]));

            $counts = ['modules' => 0, 'lessons' => 0, 'questions' => 0];
            foreach (self::list($package, 'modules', 'course') as $m => $module) {
                $where = sprintf('modules[%d]', $m);
                $moduleId = $this->step($where, fn (): int => $this->authoring->addModule($course, $author, self::str($module, 'title', $where)));
                $counts['modules']++;
                foreach (self::list($module, 'lessons', $where) as $l => $lesson) {
                    $at = sprintf('%s.lessons[%d]', $where, $l);
                    $counts['questions'] += $this->importLesson($course, $author, $moduleId, $lesson, $directory, $at);
                    $counts['lessons']++;
                }
            }

            if ($publish) {
                $this->step('publish', fn () => $this->authoring->publish($course, $author));
            }

            return ['course' => $course] + $counts;
        });
    }

    /**
     * @param array<mixed> $lesson
     * @return int questions added
     */
    private function importLesson(Course $course, CurrentUser $author, int $moduleId, array $lesson, string $directory, string $at): int
    {
        $type = self::str($lesson, 'type', $at);
        $body = isset($lesson['file'])
            ? $this->file($directory, self::str($lesson, 'file', $at), $at)
            : self::str($lesson, $type === 'quiz' ? 'intro' : 'body', $at, '');

        $lessonId = $this->step($at, fn (): int => $this->authoring->addLesson($course, $author, $moduleId, [
            'type' => $type,
            'title' => self::str($lesson, 'title', $at),
            'body' => $body,
            'media_url' => self::str($lesson, 'url', $at, ''),
            'pass_threshold' => (string) ($lesson['pass_threshold'] ?? 70),
            'max_attempts' => isset($lesson['max_attempts']) ? (string) $lesson['max_attempts'] : '',
        ]));

        if ($type !== Lesson::TYPE_QUIZ) {
            return 0;
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

        return count($questions);
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

    private function file(string $directory, string $relative, string $at): string
    {
        $base = realpath($directory);
        $full = realpath(rtrim($directory, '/') . '/' . $relative);
        // Lesson files must live inside the package.
        if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            throw new RuntimeException("$at: file \"$relative\" not found in the package.");
        }

        return (string) file_get_contents($full);
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
