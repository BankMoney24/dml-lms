<?php

declare(strict_types=1);

namespace App\Modules\Paths;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Learning\LearningService;

/**
 * Learning paths (ADR 0023): course builders put published courses in order;
 * learners follow a path and work through its courses. Courses keep their own
 * rules (price, enrolment, certificates); the path adds order and progress.
 */
final class PathService
{
    public const MIN_COURSES = 2;
    public const MAX_COURSES = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly PathRepository $paths,
        private readonly CourseRepository $courses,
        private readonly LearningService $learning,
        private readonly AuditLog $audit,
    ) {
    }

    public function create(int $tenantId, CurrentUser $author, string $title, string $summary): LearningPath
    {
        $author->authorise(Permission::CourseManage);
        [$title, $summary] = self::validDetails($title, $summary);
        $id = $this->paths->create($tenantId, $author->user->id, $this->uniqueSlug($tenantId, $title), $title, $summary);
        $this->audit->record($tenantId, $author->user->id, 'path.created', 'learning_path', $id);

        return $this->paths->find($tenantId, $id) ?? throw new \LogicException('Path not saved');
    }

    public function update(LearningPath $path, CurrentUser $author, string $title, string $summary, string $level): void
    {
        $author->authorise(Permission::CourseManage);
        [$title, $summary] = self::validDetails($title, $summary);
        if ($level !== '' && !isset(Course::LEVELS[$level])) {
            throw ValidationException::single('level', 'Choose a level, or leave it blank.');
        }
        $this->paths->update($path->tenantId, $path->id, $title, $summary, $level === '' ? null : $level);
        $this->audit->record($path->tenantId, $author->user->id, 'path.updated', 'learning_path', $path->id);
    }

    public function addCourse(LearningPath $path, CurrentUser $author, int $courseId): void
    {
        $author->authorise(Permission::CourseManage);
        $course = $this->courses->findById($path->tenantId, $courseId);
        if ($course === null) {
            throw ValidationException::single('course_id', 'Choose a course to add.');
        }
        $ids = $this->paths->courseIds($path->tenantId, $path->id);
        if (in_array($courseId, $ids, true)) {
            throw ValidationException::single('course_id', 'That course is already in this path.');
        }
        if (count($ids) >= self::MAX_COURSES) {
            throw ValidationException::single('course_id', 'A path can have up to 20 courses. Split it into two paths.');
        }
        $ids[] = $courseId;
        $this->paths->setCourses($path->tenantId, $path->id, $ids);
    }

    public function removeCourse(LearningPath $path, CurrentUser $author, int $courseId): void
    {
        $author->authorise(Permission::CourseManage);
        $ids = array_values(array_filter($this->paths->courseIds($path->tenantId, $path->id), static fn (int $id): bool => $id !== $courseId));
        if ($path->isPublished() && count($ids) < self::MIN_COURSES) {
            throw ValidationException::single('course_id', 'A published path needs at least two courses. Add another first, or unpublish it.');
        }
        $this->paths->setCourses($path->tenantId, $path->id, $ids);
    }

    /** Moves a course one place earlier (-1) or later (+1). */
    public function move(LearningPath $path, CurrentUser $author, int $courseId, int $direction): void
    {
        $author->authorise(Permission::CourseManage);
        $ids = $this->paths->courseIds($path->tenantId, $path->id);
        $at = array_search($courseId, $ids, true);
        if ($at === false) {
            throw HttpException::notFound();
        }
        $to = $at + ($direction < 0 ? -1 : 1);
        if ($to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->paths->setCourses($path->tenantId, $path->id, array_values($ids));
    }

    /** @return list<string> what stops this path being published */
    public function publishProblems(LearningPath $path): array
    {
        $courses = $this->courses->findMany($path->tenantId, $this->paths->courseIds($path->tenantId, $path->id));
        $problems = [];
        if (count($courses) < self::MIN_COURSES) {
            $problems[] = 'Add at least two courses.';
        }
        foreach ($courses as $course) {
            if (!$course->isPublished()) {
                $problems[] = '"' . $course->title . '" is not published yet.';
            }
        }

        return $problems;
    }

    public function publish(LearningPath $path, CurrentUser $author, bool $publish): void
    {
        $author->authorise(Permission::CourseManage);
        if ($publish && ($problems = $this->publishProblems($path)) !== []) {
            throw ValidationException::single('publish', implode(' ', $problems));
        }
        $this->paths->setStatus($path->tenantId, $path->id, $publish ? LearningPath::PUBLISHED : LearningPath::DRAFT);
        $this->audit->record($path->tenantId, $author->user->id, $publish ? 'path.published' : 'path.unpublished', 'learning_path', $path->id);
    }

    public function delete(LearningPath $path, CurrentUser $author): void
    {
        $author->authorise(Permission::CourseManage);
        $this->paths->delete($path->tenantId, $path->id);
        $this->audit->record($path->tenantId, $author->user->id, 'path.deleted', 'learning_path', $path->id, ['title' => $path->title]);
    }

    /**
     * The path's published courses in order, with where this person stands in each.
     *
     * @return array{steps: list<array{course: Course, state: string, done: int, lessons: int}>, completed: int, total: int, next: ?Course, complete: bool, percent: int}
     */
    public function progress(LearningPath $path, ?int $userId, bool $includeDrafts = false): array
    {
        $courses = array_values(array_filter(
            $this->courses->findMany($path->tenantId, $this->paths->courseIds($path->tenantId, $path->id)),
            static fn (Course $c): bool => $includeDrafts || $c->isPublished(),
        ));
        $ids = array_map(static fn (Course $c): int => $c->id, $courses);
        $stats = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach (
                $this->db->fetchAll(
                    "SELECT c.id AS course_id,
                            (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = c.tenant_id AND l.course_id = c.id) AS lessons,
                            e.id AS enrolment_id, e.completed_at,
                            (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.tenant_id = e.tenant_id AND lp.enrolment_id = e.id AND lp.status = 'completed') AS done
                     FROM courses c
                     LEFT JOIN enrolments e ON e.tenant_id = c.tenant_id AND e.course_id = c.id AND e.user_id = ? AND e.status <> 'withdrawn'
                     WHERE c.tenant_id = ? AND c.id IN ({$marks})",
                    [$userId ?? 0, $path->tenantId, ...$ids],
                ) as $r
            ) {
                $stats[(int) $r['course_id']] = $r;
            }
        }
        $steps = [];
        $completed = 0;
        $next = null;
        foreach ($courses as $course) {
            $r = $stats[$course->id] ?? [];
            $state = ($r['enrolment_id'] ?? null) === null ? 'none' : (($r['completed_at'] ?? null) !== null ? 'completed' : 'enrolled');
            $completed += $state === 'completed' ? 1 : 0;
            if ($next === null && $state !== 'completed') {
                $next = $course;
            }
            $steps[] = ['course' => $course, 'state' => $state, 'done' => (int) ($r['done'] ?? 0), 'lessons' => (int) ($r['lessons'] ?? 0)];
        }
        $total = count($steps);

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'next' => $next,
            'complete' => $total > 0 && $completed === $total,
            'percent' => $total === 0 ? 0 : (int) round($completed * 100 / $total),
        ];
    }

    /**
     * "Start this path": follow it and join the first unfinished course when it is
     * free (a paid course is left for the learner to pay on its page).
     */
    public function start(LearningPath $path, CurrentUser $learner): ?Course
    {
        $learner->authorise(Permission::CourseLearn);
        if (!$path->isPublished()) {
            throw HttpException::notFound();
        }
        $this->paths->follow($path->tenantId, $path->id, $learner->user->id);
        $next = $this->progress($path, $learner->user->id)['next'];
        if ($next !== null && $this->learning->findEnrolment($next, $learner) === null && !$this->learning->mustApply($next, $learner) && !$this->learning->needsPayment($next, $learner)) {
            $this->learning->enrol($next, $learner);
        }
        $this->audit->record($path->tenantId, $learner->user->id, 'path.started', 'learning_path', $path->id);

        return $next;
    }

    /** @return array{0: string, 1: string} */
    private static function validDetails(string $title, string $summary): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $summary = trim(str_replace("\r\n", "\n", $summary));
        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 190) {
            $errors['title'] = 'Give the path a name of 3 to 190 characters, for example "Junior web developer".';
        }
        if (mb_strlen($summary) > 2000) {
            $errors['summary'] = 'Keep the summary under 2,000 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$title, $summary];
    }

    private function uniqueSlug(int $tenantId, string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii === false ? '' : $ascii), '-'));
        $base = substr($base === '' ? 'path' : $base, 0, 100);
        $slug = $base;
        for ($n = 2; $this->paths->slugExists($tenantId, $slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }
}
