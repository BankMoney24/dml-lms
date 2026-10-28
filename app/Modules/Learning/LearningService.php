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
        private readonly ?\App\Modules\Applications\ApplicationRepository $applications = null,
        private readonly ?\App\Modules\Courses\CourseRepository $courses = null,
        private readonly ?\App\Modules\Payments\Plans\PaymentPlanRepository $plans = null,
        private readonly ?\App\Core\Clock $clock = null,
    ) {
    }

    /**
     * The learner's payment plan when a part is more than a week late (ADR 0037):
     * the course is paused until it is paid. Null when nothing holds them back.
     */
    public function planHold(Course $course, CurrentUser $learner): ?\App\Modules\Payments\Plans\PaymentPlan
    {
        if ($this->plans === null || !$course->isPaid() || $learner->can(Permission::CourseManage)) {
            return null;
        }
        $plan = $this->plans->current($course->tenantId, $learner->user->id, $course->id);
        $now = ($this->clock ?? new \App\Core\Clock())->now();

        return $plan !== null && $plan->isPaused($now) ? $plan : null;
    }

    /**
     * Whether this person has to apply and wait before joining (ADR 0025): the
     * course takes applications, or it is full. Course builders, people already
     * enrolled and people who were offered a place go straight in.
     */
    public function mustApply(Course $course, CurrentUser $learner): bool
    {
        if ($learner->can(Permission::CourseManage) || $this->enrolments->find($course->tenantId, $course->id, $learner->user->id) !== null) {
            return false;
        }
        if (!$this->isGated($course)) {
            return false;
        }

        return !($this->applications?->hasPlace($course->tenantId, $course->id, $learner->user->id) ?? false);
    }

    /** The course takes applications, or every place is taken. */
    public function isGated(Course $course): bool
    {
        return $course->takesApplications() || ($course->capacity !== null && $this->enrolments->countForCourse($course->tenantId, $course->id) >= $course->capacity);
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
        if ($this->mustApply($course, $learner)) {
            throw new HttpException(403, 'This course takes applications. Apply for a place on the course page.');
        }
        if ($this->needsPayment($course, $learner)) {
            // Access to a paid course follows a payment confirmed on the server (PAY-003).
            throw new HttpException(403, 'This course is paid. Pay for it on the course page to start.');
        }
        $enrolment = $this->enrolments->enrol($course->tenantId, $course->id, $learner->user->id);
        $this->applications?->markEnrolled($course->tenantId, $course->id, $learner->user->id);
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
        $this->applications?->markEnrolled($course->tenantId, $course->id, $learner->user->id);
        $this->audit->record($course->tenantId, $assignedBy, 'learning.enrolled_by_group', 'course', $course->id, ['learner' => $learner->user->id, 'group' => $groupId]);

        return true;
    }

    public function findEnrolment(Course $course, CurrentUser $user): ?Enrolment
    {
        return $this->enrolments->find($course->tenantId, $course->id, $user->user->id);
    }

    /** Learners must be enrolled to open lessons; course managers may preview. */
    public function requireEnrolment(Course $course, CurrentUser $user): Enrolment
    {
        $enrolment = $this->enrolments->find($course->tenantId, $course->id, $user->user->id);
        if ($enrolment === null || !$course->isPublished()) {
            throw HttpException::forbidden();
        }
        $hold = $this->planHold($course, $user);
        if ($hold !== null) {
            $next = (int) $hold->nextPart();
            throw new HttpException(403, sprintf(
                'This course is paused: part %d of your payment plan (%s) was due on %s. Pay it on the course’s Subscribe page and the course opens again at once.',
                $next,
                $hold->partLabel($next),
                $hold->dueAt($next)?->format('j M Y') ?? 'its due date',
            ));
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
            return $this->completeIfDone($enrolment);
        });
    }

    /**
     * Checks every unfinished place on a course again, after its completion rule
     * changed (a lesson made optional, an average lowered or removed). Learners who
     * now meet the rule finish the course and get their certificate.
     *
     * @return int how many learners finished the course because of the change
     */
    public function recheckCourse(Course $course): int
    {
        $finished = 0;
        foreach ($this->enrolments->unfinished($course->tenantId, $course->id) as $enrolment) {
            if ($this->db->transaction(fn (): bool => $this->completeIfDone($enrolment))) {
                $finished++;
            }
        }

        return $finished;
    }

    /**
     * What finishing this course takes and where the learner stands (for the course page).
     *
     * @return array{min_average: ?int, average: ?int, quizzes: int}
     */
    public function completionStanding(Course $course, ?Enrolment $enrolment): array
    {
        $avg = $enrolment === null || $course->completionMinAverage === null ? null : $this->progress->quizAverage($course->tenantId, $enrolment->id, $course->id);

        return ['min_average' => $course->completionMinAverage, 'average' => $avg['average'] ?? null, 'quizzes' => $avg['quizzes'] ?? 0];
    }

    /** Takes a learner off a course (after a refund, ADR 0032). Progress and certificates are kept. */
    public function withdraw(int $tenantId, int $courseId, int $userId, int $byUserId): bool
    {
        $done = $this->enrolments->withdraw($tenantId, $courseId, $userId);
        if ($done) {
            $this->audit->record($tenantId, $byUserId, 'learning.withdrawn', 'course', $courseId, ['learner' => $userId]);
        }

        return $done;
    }

    private function completeIfDone(Enrolment $enrolment): bool
    {
        $minAverage = $this->courses?->findById($enrolment->tenantId, $enrolment->courseId)?->completionMinAverage;
        $average = $minAverage === null ? null : ($this->progress->quizAverage($enrolment->tenantId, $enrolment->id, $enrolment->courseId)['average'] ?? null);
        $complete = $this->rule->isComplete(
            $this->lessons->mandatoryLessonIds($enrolment->tenantId, $enrolment->courseId),
            $this->progress->completedLessonIds($enrolment->tenantId, $enrolment->id),
            $minAverage,
            $average,
        );
        if ($complete) {
            $this->enrolments->markCompleted($enrolment->tenantId, $enrolment->id);
            $this->audit->record($enrolment->tenantId, $enrolment->userId, 'learning.course_completed', 'course', $enrolment->courseId);
            $this->certificates->issueFor($enrolment);
        }

        return $complete;
    }

    /** @return list<int> */
    public function completedLessonIds(Enrolment $enrolment): array
    {
        return $this->progress->completedLessonIds($enrolment->tenantId, $enrolment->id);
    }
}
