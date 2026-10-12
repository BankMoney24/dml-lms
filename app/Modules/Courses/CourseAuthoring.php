<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;

/** Educator use cases for building and publishing a course. */
final class CourseAuthoring
{
    public function __construct(
        private readonly Connection $db,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly AssessmentRepository $assessments,
        private readonly AuditLog $audit,
    ) {
    }

    public function createCourse(int $tenantId, CurrentUser $author, string $title, string $summary): Course
    {
        $author->authorise(Permission::CourseManage);
        $title = self::clean($title);
        $summary = trim($summary);
        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 190) {
            $errors['title'] = 'Give the course a title between 3 and 190 characters.';
        }
        if (mb_strlen($summary) > 2000) {
            $errors['summary'] = 'Keep the summary under 2,000 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $course = $this->courses->create($tenantId, $author->user->id, $this->uniqueSlug($tenantId, $title), $title, $summary);
        $this->audit->record($tenantId, $author->user->id, 'course.created', 'course', $course->id);

        return $course;
    }

    public function addModule(Course $course, CurrentUser $author, string $title): int
    {
        $this->authoriseFor($course, $author);
        $title = self::clean($title);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 190) {
            throw ValidationException::single('module_title', 'Give the module a title between 2 and 190 characters.');
        }
        $id = $this->lessons->addModule($course->tenantId, $course->id, $title);
        $this->courses->touch($course->tenantId, $course->id);

        return $id;
    }

    /**
     * @param array{type: string, title: string, body: string, pass_threshold?: string, max_attempts?: string} $input
     */
    public function addLesson(Course $course, CurrentUser $author, int $moduleId, array $input): int
    {
        $this->authoriseFor($course, $author);
        if (!$this->lessons->moduleBelongsToCourse($course->tenantId, $course->id, $moduleId)) {
            throw ValidationException::single('module_id', 'Choose a module from this course.');
        }

        $type = $input['type'];
        $title = self::clean($input['title']);
        $body = trim($input['body']);
        $errors = [];
        if (!in_array($type, [Lesson::TYPE_TEXT, Lesson::TYPE_QUIZ], true)) {
            $errors['type'] = 'Choose a lesson type.';
        }
        if (mb_strlen($title) < 2 || mb_strlen($title) > 190) {
            $errors['lesson_title'] = 'Give the lesson a title between 2 and 190 characters.';
        }
        if ($type === Lesson::TYPE_TEXT && $body === '') {
            $errors['body'] = 'Add the lesson text learners will read.';
        }
        if (mb_strlen($body) > 50000) {
            $errors['body'] = 'Keep lesson text under 50,000 characters; split longer material into several lessons.';
        }

        $threshold = 70;
        $maxAttempts = null;
        if ($type === Lesson::TYPE_QUIZ) {
            $rawThreshold = trim($input['pass_threshold'] ?? '70');
            if (!ctype_digit($rawThreshold) || (int) $rawThreshold > 100) {
                $errors['pass_threshold'] = 'Enter a pass mark from 0 to 100.';
            } else {
                $threshold = (int) $rawThreshold;
            }
            $rawAttempts = trim($input['max_attempts'] ?? '');
            if ($rawAttempts !== '') {
                if (!ctype_digit($rawAttempts) || (int) $rawAttempts < 1 || (int) $rawAttempts > 20) {
                    $errors['max_attempts'] = 'Leave blank for unlimited, or enter 1 to 20 attempts.';
                } else {
                    $maxAttempts = (int) $rawAttempts;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->db->transaction(function () use ($course, $moduleId, $type, $title, $body, $threshold, $maxAttempts): int {
            $lessonId = $this->lessons->addLesson($course->tenantId, $course->id, $moduleId, $type, $title, $body);
            if ($type === Lesson::TYPE_QUIZ) {
                $this->assessments->create($course->tenantId, $lessonId, $threshold, $maxAttempts);
            }
            $this->courses->touch($course->tenantId, $course->id);

            return $lessonId;
        });
    }

    /** @param list<string> $options */
    public function addQuestion(Course $course, CurrentUser $author, int $lessonId, string $prompt, array $options, string $correct): int
    {
        $this->authoriseFor($course, $author);
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $lessonId);
        $assessment = $lesson === null ? null : $this->assessments->findForLesson($course->tenantId, $lesson->id);
        if ($assessment === null) {
            throw HttpException::notFound();
        }

        $prompt = trim($prompt);
        $labels = [];
        $correctIndex = null;
        foreach ($options as $i => $label) {
            $label = self::clean($label);
            if ($label === '') {
                continue;
            }
            if ((string) $i === $correct) {
                $correctIndex = count($labels);
            }
            $labels[] = $label;
        }

        $errors = [];
        if (mb_strlen($prompt) < 3 || mb_strlen($prompt) > 1000) {
            $errors['prompt'] = 'Write a question between 3 and 1,000 characters.';
        }
        if (count($labels) < 2) {
            $errors['options'] = 'Add at least two answer options.';
        } elseif ($correctIndex === null) {
            $errors['correct'] = 'Mark which filled-in option is correct.';
        }
        foreach ($labels as $label) {
            if (mb_strlen($label) > 500) {
                $errors['options'] = 'Keep each option under 500 characters.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->assessments->addQuestion($course->tenantId, $assessment['id'], $prompt, $labels, (int) $correctIndex);
        $this->courses->touch($course->tenantId, $course->id);

        return $id;
    }

    /** @return list<string> reasons the course cannot be published yet */
    public function publishProblems(Course $course): array
    {
        $problems = [];
        $lessons = $this->lessons->orderedLessons($course->tenantId, $course->id);
        if ($lessons === []) {
            $problems[] = 'Add at least one lesson.';
        }
        foreach ($lessons as $lesson) {
            if (!$lesson->isQuiz()) {
                continue;
            }
            $assessment = $this->assessments->findForLesson($course->tenantId, $lesson->id);
            if ($assessment === null || $this->assessments->questionCount($course->tenantId, $assessment['id']) === 0) {
                $problems[] = sprintf('Add at least one question to the quiz “%s”.', $lesson->title);
            }
        }

        return $problems;
    }

    public function publish(Course $course, CurrentUser $author): void
    {
        $this->authoriseFor($course, $author);
        $problems = $this->publishProblems($course);
        if ($problems !== []) {
            throw ValidationException::single('publish', implode(' ', $problems));
        }
        $this->courses->markPublished($course->tenantId, $course->id);
        $this->audit->record($course->tenantId, $author->user->id, 'course.published', 'course', $course->id);
    }

    private function authoriseFor(Course $course, CurrentUser $author): void
    {
        $author->authorise(Permission::CourseManage);
        if ($author->membership === null || $author->membership->tenantId !== $course->tenantId) {
            throw HttpException::forbidden();
        }
    }

    private function uniqueSlug(int $tenantId, string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? '' : $ascii), '-'));
        $base = substr($base === '' ? 'course' : $base, 0, 100);
        $slug = $base;
        for ($n = 2; $this->courses->slugExists($tenantId, $slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
