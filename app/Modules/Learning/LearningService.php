<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;

final class LearningService
{
    public function __construct(
        private readonly Connection $db,
        private readonly EnrolmentRepository $enrolments,
        private readonly ProgressRepository $progress,
        private readonly LessonRepository $lessons,
        private readonly CompletionRule $rule,
        private readonly AuditLog $audit,
    ) {
    }

    public function enrol(Course $course, CurrentUser $learner): Enrolment
    {
        $learner->authorise(Permission::CourseLearn);
        if (!$course->isPublished()) {
            throw HttpException::notFound();
        }
        $existing = $this->enrolments->find($course->tenantId, $course->id, $learner->user->id);
        if ($existing !== null) {
            return $existing;
        }
        $enrolment = $this->enrolments->enrol($course->tenantId, $course->id, $learner->user->id);
        $this->audit->record($course->tenantId, $learner->user->id, 'learning.enrolled', 'course', $course->id);

        return $enrolment;
    }

    /** Learners must be enrolled to open lessons; course managers may preview. */
    public function requireEnrolment(Course $course, CurrentUser $user): Enrolment
    {
        $enrolment = $this->enrolments->find($course->tenantId, $course->id, $user->user->id);
        if ($enrolment === null || !$course->isPublished()) {
            throw HttpException::forbidden();
        }

        return $enrolment;
    }

    public function markOpened(Enrolment $enrolment, Lesson $lesson): void
    {
        $this->progress->markStarted($enrolment->tenantId, $enrolment->id, $lesson->id);
    }

    public function completeTextLesson(Enrolment $enrolment, Lesson $lesson): bool
    {
        if ($lesson->isQuiz()) {
            // Quiz lessons complete only through a passing attempt.
            throw HttpException::badRequest('Finish the quiz to complete this lesson.');
        }

        return $this->recordLessonCompleted($enrolment, $lesson);
    }

    /** @return bool whether this made the whole course complete */
    public function recordLessonCompleted(Enrolment $enrolment, Lesson $lesson): bool
    {
        return $this->db->transaction(function () use ($enrolment, $lesson): bool {
            $this->progress->markCompleted($enrolment->tenantId, $enrolment->id, $lesson->id);
            if ($enrolment->isCompleted()) {
                return false;
            }
            $complete = $this->rule->isComplete(
                $this->lessons->mandatoryLessonIds($enrolment->tenantId, $enrolment->courseId),
                $this->progress->completedLessonIds($enrolment->tenantId, $enrolment->id),
            );
            if ($complete) {
                $this->enrolments->markCompleted($enrolment->tenantId, $enrolment->id);
                $this->audit->record($enrolment->tenantId, $enrolment->userId, 'learning.course_completed', 'course', $enrolment->courseId);
            }

            return $complete;
        });
    }

    /** @return list<int> */
    public function completedLessonIds(Enrolment $enrolment): array
    {
        return $this->progress->completedLessonIds($enrolment->tenantId, $enrolment->id);
    }
}
