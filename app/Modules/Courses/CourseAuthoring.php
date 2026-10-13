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
use App\Modules\Payments\Money;
use App\Modules\VirtualLabs\LabCatalogue;

/**
 * Educator use cases for building, editing and publishing a course.
 *
 * Editing rule: anything learners have already used (opened lessons, quiz
 * attempts) is never deleted, because that would silently rewrite their
 * history. Text can always be corrected.
 */
final class CourseAuthoring
{
    public function __construct(
        private readonly Connection $db,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly AssessmentRepository $assessments,
        private readonly AuditLog $audit,
        private readonly LabCatalogue $labs = new LabCatalogue([]),
    ) {
    }

    public function createCourse(int $tenantId, CurrentUser $author, string $title, string $summary): Course
    {
        $author->authorise(Permission::CourseManage);
        [$title, $summary] = $this->validDetails($title, $summary);
        $course = $this->courses->create($tenantId, $author->user->id, $this->uniqueSlug($tenantId, $title), $title, $summary);
        $this->audit->record($tenantId, $author->user->id, 'course.created', 'course', $course->id);

        return $course;
    }

    /**
     * @param array{title: string, summary: string, level?: string, duration_hours?: string, outcomes?: string, prerequisites?: string, track?: string} $input
     */
    public function updateDetails(Course $course, CurrentUser $author, array $input): void
    {
        $this->authoriseFor($course, $author);
        $errors = [];
        try {
            [$title, $summary] = $this->validDetails($input['title'], $input['summary']);
        } catch (ValidationException $e) {
            $errors = $e->errors;
            $title = $summary = '';
        }
        try {
            $profile = $this->validProfile($input);
        } catch (ValidationException $e) {
            $errors += $e->errors;
            $profile = null;
        }
        if ($errors !== [] || $profile === null) {
            throw new ValidationException($errors);
        }
        $this->db->transaction(function () use ($course, $title, $summary, $profile): void {
            $this->courses->updateDetails($course->tenantId, $course->id, $title, $summary);
            $this->courses->updateProfile($course->tenantId, $course->id, $profile['level'], $profile['hours'], $profile['outcomes'], $profile['prerequisites'], $profile['track']);
        });
        $this->audit->record($course->tenantId, $author->user->id, 'course.updated', 'course', $course->id);
    }

    /**
     * @param array{level?: string, duration_hours?: string, outcomes?: string, prerequisites?: string, track?: string} $input
     * @return array{level: ?string, hours: ?int, outcomes: list<string>, prerequisites: string, track: ?string}
     */
    public function validProfile(array $input): array
    {
        $errors = [];
        $level = trim($input['level'] ?? '');
        if ($level !== '' && !isset(Course::LEVELS[$level])) {
            $errors['level'] = 'Choose beginner, intermediate or advanced.';
        }
        $track = trim($input['track'] ?? '');
        if ($track !== '' && !isset(Course::TRACKS[$track])) {
            $errors['track'] = 'Choose school subject or skills.';
        }
        $rawHours = trim($input['duration_hours'] ?? '');
        $hours = null;
        if ($rawHours !== '') {
            if (!ctype_digit($rawHours) || (int) $rawHours < 1 || (int) $rawHours > 2000) {
                $errors['duration_hours'] = 'Enter the expected hours as a whole number from 1 to 2,000, or leave it blank.';
            } else {
                $hours = (int) $rawHours;
            }
        }
        $outcomes = Course::lines($input['outcomes'] ?? '');
        if (count($outcomes) > 20) {
            $errors['outcomes'] = 'List at most 20 outcomes.';
        }
        foreach ($outcomes as $outcome) {
            if (mb_strlen($outcome) > 300) {
                $errors['outcomes'] = 'Keep each outcome under 300 characters.';
            }
        }
        $prerequisites = trim($input['prerequisites'] ?? '');
        if (mb_strlen($prerequisites) > 2000) {
            $errors['prerequisites'] = 'Keep the prerequisites under 2,000 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['level' => $level === '' ? null : $level, 'hours' => $hours, 'outcomes' => $outcomes, 'prerequisites' => $prerequisites, 'track' => $track === '' ? null : $track];
    }

    /**
     * Sets or clears the course price. Only school administrators decide what
     * a course costs; a blank price makes it free. Payments already started
     * keep the amount they started with.
     */
    public function setPrice(Course $course, CurrentUser $admin, string $priceText, string $currency): void
    {
        $admin->authorise(Permission::SchoolManage);
        $priceText = trim($priceText);
        if ($priceText === '' || $priceText === '0') {
            $this->courses->updatePrice($course->tenantId, $course->id, null, null);
            $this->audit->record($course->tenantId, $admin->user->id, 'course.price_cleared', 'course', $course->id);

            return;
        }
        $errors = [];
        $minor = Money::parse($priceText);
        if ($minor === null || $minor < Money::MIN_MINOR || $minor > Money::MAX_MINOR) {
            $errors['price'] = 'Enter a price of at least 100, for example 15000, or leave it blank for a free course.';
        }
        if (!Money::isSupported($currency)) {
            $errors['currency'] = 'Choose a currency.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->courses->updatePrice($course->tenantId, $course->id, $minor, $currency);
        $this->audit->record($course->tenantId, $admin->user->id, 'course.priced', 'course', $course->id, ['amount' => $minor, 'currency' => $currency]);
    }

    public function addModule(Course $course, CurrentUser $author, string $title): int
    {
        $this->authoriseFor($course, $author);
        $id = $this->lessons->addModule($course->tenantId, $course->id, $this->validModuleTitle($title));
        $this->courses->touch($course->tenantId, $course->id);

        return $id;
    }

    public function renameModule(Course $course, CurrentUser $author, int $moduleId, string $title): void
    {
        $this->authoriseFor($course, $author);
        $this->requireModule($course, $moduleId);
        $this->lessons->renameModule($course->tenantId, $course->id, $moduleId, $this->validModuleTitle($title));
        $this->courses->touch($course->tenantId, $course->id);
    }

    public function deleteModule(Course $course, CurrentUser $author, int $moduleId): void
    {
        $this->authoriseFor($course, $author);
        $this->requireModule($course, $moduleId);
        if ($this->lessons->lessonCountInModule($course->tenantId, $moduleId) > 0) {
            throw ValidationException::single('module', 'Move or delete the lessons in this module first.');
        }
        $this->lessons->deleteModule($course->tenantId, $course->id, $moduleId);
        $this->courses->touch($course->tenantId, $course->id);
    }

    /**
     * @param array{type: string, title: string, body: string, media_url?: string, pass_threshold?: string, max_attempts?: string, lab_key?: string} $input
     */
    public function addLesson(Course $course, CurrentUser $author, int $moduleId, array $input): int
    {
        $this->authoriseFor($course, $author);
        if (!$this->lessons->moduleBelongsToCourse($course->tenantId, $course->id, $moduleId)) {
            throw ValidationException::single('module_id', 'Choose a module from this course.');
        }
        $type = $input['type'];
        if (!isset(Lesson::TYPES[$type])) {
            throw ValidationException::single('type', 'Choose a lesson type.');
        }
        $valid = $this->validLesson($type, $input);

        return $this->db->transaction(function () use ($course, $moduleId, $type, $valid): int {
            $lessonId = $this->lessons->addLesson($course->tenantId, $course->id, $moduleId, $type, $valid['title'], $valid['body'], $valid['media_url'], $valid['lab_key']);
            if ($type === Lesson::TYPE_QUIZ) {
                $this->assessments->create($course->tenantId, $lessonId, $valid['threshold'], $valid['max_attempts']);
            }
            $this->courses->touch($course->tenantId, $course->id);

            return $lessonId;
        });
    }

    /**
     * @param array{title: string, body: string, media_url?: string, pass_threshold?: string, max_attempts?: string, preview?: string} $input
     */
    public function updateLesson(Course $course, CurrentUser $author, int $lessonId, array $input): void
    {
        $this->authoriseFor($course, $author);
        $lesson = $this->requireLesson($course, $lessonId);
        // A lab lesson keeps its lab; to use another lab, add a new lesson.
        $valid = $this->validLesson($lesson->type, ['lab_key' => (string) $lesson->labKey] + $input + ['type' => $lesson->type]);

        $preview = $lesson->canBePreview() && ($input['preview'] ?? '') === '1';
        $this->db->transaction(function () use ($course, $lesson, $valid, $author, $preview): void {
            $this->lessons->updateLesson($course->tenantId, $course->id, $lesson->id, $valid['title'], $valid['body'], $valid['media_url'], $preview);
            if ($lesson->isQuiz()) {
                $assessment = $this->assessments->findForLesson($course->tenantId, $lesson->id);
                if ($assessment !== null) {
                    $this->assessments->updateSettings($course->tenantId, $assessment['id'], $valid['threshold'], $valid['max_attempts']);
                }
            }
            $this->courses->touch($course->tenantId, $course->id);
            $this->audit->record($course->tenantId, $author->user->id, 'lesson.updated', 'lesson', $lesson->id);
        });
    }

    public function moveLesson(Course $course, CurrentUser $author, int $lessonId, string $direction): void
    {
        $this->authoriseFor($course, $author);
        if (!in_array($direction, ['up', 'down'], true)) {
            throw HttpException::badRequest();
        }
        $this->db->transaction(function () use ($course, $lessonId, $direction): void {
            $lesson = $this->requireLesson($course, $lessonId);
            $this->lessons->move($course->tenantId, $lesson, $direction);
            $this->courses->touch($course->tenantId, $course->id);
        });
    }

    public function deleteLesson(Course $course, CurrentUser $author, int $lessonId): void
    {
        $this->authoriseFor($course, $author);
        $lesson = $this->requireLesson($course, $lessonId);
        $assessment = $lesson->isQuiz() ? $this->assessments->findForLesson($course->tenantId, $lesson->id) : null;
        if (
            $this->lessons->hasLearnerActivity($course->tenantId, $lesson->id)
            || ($assessment !== null && $this->assessments->attemptCount($course->tenantId, $assessment['id']) > 0)
        ) {
            throw ValidationException::single('lesson', 'Learners have already used this lesson, so it cannot be deleted. You can still edit its text.');
        }
        $this->lessons->deleteLesson($course->tenantId, $course->id, $lesson->id);
        $this->courses->touch($course->tenantId, $course->id);
        $this->audit->record($course->tenantId, $author->user->id, 'lesson.deleted', 'lesson', $lesson->id);
    }

    /** @param list<string> $options */
    public function addQuestion(Course $course, CurrentUser $author, int $lessonId, string $prompt, array $options, string $correct, string $explanation = ''): int
    {
        $this->authoriseFor($course, $author);
        $assessment = $this->assessmentFor($course, $lessonId);

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
        $explanation = trim($explanation);
        if (mb_strlen($explanation) > 2000) {
            $errors['explanation'] = 'Keep the explanation under 2,000 characters.';
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

        $id = $this->assessments->addQuestion($course->tenantId, $assessment['id'], $prompt, $labels, (int) $correctIndex, $explanation);
        $this->courses->touch($course->tenantId, $course->id);

        return $id;
    }

    /** Explanations can be improved at any time: they do not change how attempts were scored. */
    public function updateExplanation(Course $course, CurrentUser $author, int $lessonId, int $questionId, string $explanation): void
    {
        $this->authoriseFor($course, $author);
        $assessment = $this->assessmentFor($course, $lessonId);
        $explanation = trim($explanation);
        if (mb_strlen($explanation) > 2000) {
            throw ValidationException::single('explanation', 'Keep the explanation under 2,000 characters.');
        }
        if (!$this->assessments->updateExplanation($course->tenantId, $assessment['id'], $questionId, $explanation)) {
            // Same text as before also reports 0 rows; check the question exists.
            $exists = array_filter($this->assessments->questions($course->tenantId, $assessment['id']), static fn ($q): bool => $q->id === $questionId);
            if ($exists === []) {
                throw HttpException::notFound();
            }
        }
        $this->courses->touch($course->tenantId, $course->id);
    }

    public function deleteQuestion(Course $course, CurrentUser $author, int $lessonId, int $questionId): void
    {
        $this->authoriseFor($course, $author);
        $assessment = $this->assessmentFor($course, $lessonId);
        if ($this->assessments->attemptCount($course->tenantId, $assessment['id']) > 0) {
            throw ValidationException::single('question', 'Learners have already answered this quiz, so its questions cannot be removed.');
        }
        if (!$this->assessments->deleteQuestion($course->tenantId, $assessment['id'], $questionId)) {
            throw HttpException::notFound();
        }
        $this->courses->touch($course->tenantId, $course->id);
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
            if ($lesson->isVideo() && $lesson->video() === null) {
                $problems[] = sprintf('Add a YouTube or Vimeo link to the video lesson “%s”.', $lesson->title);
            }
            if ($lesson->isLab() && $this->labs->find($lesson->labKey) === null) {
                $problems[] = sprintf('The lab lesson “%s” uses a lab this version does not have. Delete it or choose another lab.', $lesson->title);
            }
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

    /** Hides the course from the catalogue and from enrolled learners until it is published again. */
    public function unpublish(Course $course, CurrentUser $author): void
    {
        $this->authoriseFor($course, $author);
        $this->courses->markDraft($course->tenantId, $course->id);
        $this->audit->record($course->tenantId, $author->user->id, 'course.unpublished', 'course', $course->id);
    }

    private function authoriseFor(Course $course, CurrentUser $author): void
    {
        $author->authorise(Permission::CourseManage);
        if ($author->membership === null || $author->membership->tenantId !== $course->tenantId) {
            throw HttpException::forbidden();
        }
    }

    private function requireLesson(Course $course, int $lessonId): Lesson
    {
        return $this->lessons->findInCourse($course->tenantId, $course->id, $lessonId) ?? throw HttpException::notFound();
    }

    private function requireModule(Course $course, int $moduleId): void
    {
        if ($this->lessons->findModule($course->tenantId, $course->id, $moduleId) === null) {
            throw HttpException::notFound();
        }
    }

    /** @return array{id: int, pass_threshold: int, max_attempts: ?int} */
    private function assessmentFor(Course $course, int $lessonId): array
    {
        $lesson = $this->requireLesson($course, $lessonId);

        return $this->assessments->findForLesson($course->tenantId, $lesson->id) ?? throw HttpException::notFound();
    }

    /** @return array{0: string, 1: string} */
    private function validDetails(string $title, string $summary): array
    {
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

        return [$title, $summary];
    }

    private function validModuleTitle(string $title): string
    {
        $title = self::clean($title);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 190) {
            throw ValidationException::single('module_title', 'Give the module a title between 2 and 190 characters.');
        }

        return $title;
    }

    /**
     * @param array{type: string, title: string, body: string, media_url?: string, pass_threshold?: string, max_attempts?: string, lab_key?: string} $input
     * @return array{title: string, body: string, media_url: ?string, threshold: int, max_attempts: ?int, lab_key: ?string}
     */
    private function validLesson(string $type, array $input): array
    {
        $title = self::clean($input['title']);
        $body = trim($input['body']);
        $errors = [];
        $labKey = null;
        if ($type === Lesson::TYPE_LAB) {
            $lab = $this->labs->find(trim($input['lab_key'] ?? ''));
            if ($lab === null) {
                $errors['lab_key'] = 'Choose a lab from the catalogue.';
            } else {
                $labKey = $lab->definition()->key;
                $title = $title === '' ? $lab->definition()->title : $title;
            }
        }
        if (mb_strlen($title) < 2 || mb_strlen($title) > 190) {
            $errors['lesson_title'] = 'Give the lesson a title between 2 and 190 characters.';
        }
        if ($type === Lesson::TYPE_TEXT && $body === '') {
            $errors['body'] = 'Add the lesson text learners will read.';
        }
        if ($type === Lesson::TYPE_ASSIGNMENT && $body === '') {
            $errors['body'] = 'Describe the task: what to do, what to submit, and how it will be judged.';
        }
        $mediaUrl = null;
        if ($type === Lesson::TYPE_VIDEO) {
            $raw = trim($input['media_url'] ?? '');
            $video = $raw === '' || mb_strlen($raw) > 500 ? null : VideoLink::parse($raw);
            if ($video === null) {
                $errors['media_url'] = 'Paste a YouTube or Vimeo link, for example https://www.youtube.com/watch?v=… or https://vimeo.com/123456789.';
            } else {
                $mediaUrl = $video->canonical();
            }
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

        return ['title' => $title, 'body' => $body, 'media_url' => $mediaUrl, 'threshold' => $threshold, 'max_attempts' => $maxAttempts, 'lab_key' => $labKey];
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
