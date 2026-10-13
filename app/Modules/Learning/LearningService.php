<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Modules\Audit\AuditLog;
use App\Modules\Certificates\CertificateService;
use App\Modules\Courses\Course;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Payments\PaymentRepository;

final class LearningService
{
    public function __construct(
        private readonly Connection $db,
        private readonly EnrolmentRepository $enrolments,
        private readonly ProgressRepository $progress,
        private readonly LessonRepository $lessons,
        private readonly CompletionRule $rule,
        private readonly AuditLog $audit,
        private readonly CertificateService $certificates,
        private readonly PaymentRepository $payments,
    ) {
    }

    /** Paid courses need a confirmed payment first; people who build courses can always look inside. */
    public function needsPayment(Course $course, CurrentUser $learner): bool
    {
        return $course->isPaid()
            && !$learner->can(Permission::CourseManage)
            && !$this->payments->hasPaid($course->tenantId, $learner->user->id, $course->id);
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
        if ($this->needsPayment($course, $learner)) {
            // Access to a paid course follows a payment confirmed on the server (PAY-003).
            throw new HttpException(403, 'This course is paid. Pay for it on the course page to start.');
        }
        $enrolment = $this->enrolments->enrol($course->tenantId, $course->id, $learner->user->id);
        $this->audit->record($course->tenantId, $learner->user->id, 'learning.enrolled', 'course', $course->id);

        return $enrolment;
    }

    /**
     * Enrols a learner because their class or cohort was given the course.
     * The school chose it for them, so a price is not charged to the learner.
     * Returns true only when a new enrolment was made: false when already
     * enrolled, the course is not open (unpublished) or the person cannot
     * learn here.
     */
    public function enrolForGroup(Course $course, CurrentUser $learner, int $assignedBy, int $groupId): bool
    {
        if (!$course->isPublished() || !$learner->can(Permission::CourseLearn)) {
            return false;
        }
        if ($this->enrolments->find($course->tenantId, $course->id, $learner->user->id) !== null) {
            return false;
        }
        $this->enrolments->enrol($course->tenantId, $course->id, $learner->user->id);
        $this->audit->record($course->tenantId, $assignedBy, 'learning.enrolled_by_group', 'course', $course->id, ['learner' => $learner->user->id, 'group' => $groupId]);

        return true;
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
        if (!$lesson->learnerCompletes()) {
            // Quizzes complete through a passing attempt, assignments through a teacher's approval.
            throw HttpException::badRequest($lesson->isQuiz() ? 'Finish the quiz to complete this lesson.' : 'Submit your work; a teacher will review it.');
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
                $this->certificates->issueFor($enrolment);
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
