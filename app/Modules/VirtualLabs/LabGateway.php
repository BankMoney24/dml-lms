<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Learning\Enrolment;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Tenancy\Tenant;

/**
 * The lab gateway (LAB-003): the only way a lab session starts, is opened,
 * is marked and ends.
 *
 *  - launch: an enrolled learner on a lab lesson of a published course; at
 *    most one open session per learner and lesson; at most 30 launches an
 *    hour per learner; a deadline from the lab's time limit.
 *  - every later operation re-checks that the session belongs to this
 *    school and this learner, is still open, is on the current lab version
 *    and within its deadline, and that the lesson still exists (LAB-006).
 *  - marking happens here, from the seed, never from anything the browser
 *    says the answer is. Only answers and small work records are kept
 *    (LAB-007), capped in size.
 */
final class LabGateway
{
    public const LAUNCHES_PER_HOUR = 30;
    /** Allowance for a slow connection when the learner presses "Check" just before the deadline. */
    public const GRACE_SECONDS = 120;
    public const MAX_EVIDENCE_BYTES = 16384;

    public function __construct(
        private readonly Connection $db,
        private readonly LabCatalogue $catalogue,
        private readonly LabSessionRepository $sessions,
        private readonly LessonRepository $lessons,
        private readonly EnrolmentRepository $enrolments,
        private readonly LearningService $learning,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public function labFor(Lesson $lesson): Lab
    {
        $lab = $lesson->isLab() ? $this->catalogue->find($lesson->labKey) : null;
        if ($lab === null) {
            throw new HttpException(404, 'This lab is not available. Ask your teacher to check the lesson.');
        }

        return $lab;
    }

    public function launch(Course $course, Lesson $lesson, Enrolment $enrolment, CurrentUser $user, string $clientIp): LabSession
    {
        $lab = $this->labFor($lesson);
        $definition = $lab->definition();
        $now = $this->clock->now();
        if ($this->sessions->launchesSince($course->tenantId, $user->user->id, $now->modify('-1 hour')->format('Y-m-d H:i:s')) >= self::LAUNCHES_PER_HOUR) {
            throw ValidationException::single('lab', 'You have started this many labs in the last hour. Take a break and try again later.');
        }

        $id = $this->db->transaction(function () use ($course, $lesson, $enrolment, $user, $definition, $now): int {
            $this->sessions->closeOpen($course->tenantId, $enrolment->id, $lesson->id);

            return $this->sessions->create([
                'tenant_id' => $course->tenantId,
                'user_id' => $user->user->id,
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
                'enrolment_id' => $enrolment->id,
                'lab_key' => $definition->key,
                'lab_version' => $definition->version,
                'seed' => Seed::fresh(),
                'expires_at' => $now->modify('+' . $definition->minutes . ' minutes')->format('Y-m-d H:i:s'),
            ]);
        });
        $this->audit->record($course->tenantId, $user->user->id, 'lab.launched', 'lesson', $lesson->id, ['lab' => $definition->key, 'version' => $definition->version], $clientIp);

        return $this->sessions->find($course->tenantId, $id) ?? throw new \RuntimeException('Lab session not stored.');
    }

    /** The session, if it is this learner's in this school; expires it on the way if its time is up. */
    public function sessionFor(Tenant $tenant, CurrentUser $user, int $id): LabSession
    {
        $session = $this->sessions->find($tenant->id, $id);
        if ($session === null || $session->userId !== $user->user->id) {
            throw HttpException::notFound();
        }
        if ($session->isOpen() && $this->pastDeadline($session)) {
            $this->sessions->markClosed($tenant->id, $session->id, LabSession::EXPIRED);
            $this->audit->record($tenant->id, $user->user->id, 'lab.expired', 'lesson', $session->lessonId, ['lab' => $session->labKey]);
            $session = $this->sessions->find($tenant->id, $id) ?? $session;
        }

        return $session;
    }

    /** @return array{public: array<string, mixed>, secret: array<string, mixed>} */
    public function paramsFor(LabSession $session): array
    {
        return $this->labForSession($session)->params(new Seed($session->seed));
    }

    public function labForSession(LabSession $session): Lab
    {
        $lab = $this->catalogue->find($session->labKey);
        if ($lab === null) {
            throw new HttpException(404, 'This lab is no longer available.');
        }

        return $lab;
    }

    /**
     * Marks the answers. Returns whether passing it completed the whole course.
     *
     * @param array<string, string> $answers
     */
    public function submit(Tenant $tenant, CurrentUser $user, int $sessionId, array $answers, string $clientIp): bool
    {
        $session = $this->sessionFor($tenant, $user, $sessionId);
        if (!$session->isOpen()) {
            throw ValidationException::single('lab', $session->status === LabSession::EXPIRED
                ? 'Time ran out for this attempt, so it cannot be marked. Start the lab again: you will get new numbers.'
                : 'This attempt is already finished. Start the lab again to have another go.');
        }
        $lab = $this->labForSession($session);
        if ($lab->definition()->version !== $session->labVersion) {
            $this->sessions->markClosed($tenant->id, $session->id, LabSession::ENDED);
            throw ValidationException::single('lab', 'This lab was updated while you were working on it. Start it again to get the new version.');
        }
        $lesson = $this->lessons->findInCourse($tenant->id, $session->courseId, $session->lessonId);
        $enrolment = $this->enrolments->findById($tenant->id, $session->enrolmentId);
        if ($lesson === null || $enrolment === null || $enrolment->userId !== $user->user->id || $lesson->labKey !== $session->labKey) {
            $this->sessions->markClosed($tenant->id, $session->id, LabSession::ENDED);
            throw HttpException::notFound();
        }

        $answers = array_map(static fn (string $a): string => mb_substr($a, 0, 20000), array_slice($answers, 0, 40, true));
        $result = $lab->check($lab->params(new Seed($session->seed)), $answers);
        $passed = $result->score >= $lab->definition()->passMark;

        $courseDone = $this->db->transaction(function () use ($tenant, $session, $result, $passed, $enrolment, $lesson): bool {
            $locked = $this->sessions->lock($tenant->id, $session->id);
            if ($locked === null || !$locked->isOpen()) {
                throw ValidationException::single('lab', 'This attempt is already finished.');
            }
            $this->sessions->markSubmitted($tenant->id, $session->id, $result->score, $passed, self::capped($result->evidence), $result->feedback);

            return $passed && $this->learning->recordLessonCompleted($enrolment, $lesson);
        });
        $this->audit->record($tenant->id, $user->user->id, 'lab.submitted', 'lesson', $session->lessonId, [
            'lab' => $session->labKey,
            'score' => $result->score,
            'passed' => $passed ? 'yes' : 'no',
        ], $clientIp);

        return $courseDone;
    }

    public function end(Tenant $tenant, CurrentUser $user, int $sessionId): LabSession
    {
        $session = $this->sessionFor($tenant, $user, $sessionId);
        if ($session->isOpen()) {
            $this->sessions->markClosed($tenant->id, $session->id, LabSession::ENDED);
        }

        return $this->sessions->find($tenant->id, $sessionId) ?? $session;
    }

    /** For the scheduled clean-up. @return int sessions expired */
    public function expireStale(): int
    {
        return $this->sessions->expireStale($this->clock->now()->modify('-' . self::GRACE_SECONDS . ' seconds')->format('Y-m-d H:i:s'));
    }

    public function pastDeadline(LabSession $session): bool
    {
        return $this->clock->now()->getTimestamp() > (int) strtotime($session->expiresAt . ' UTC') + self::GRACE_SECONDS;
    }

    /**
     * Keeps evidence small: long strings are cut, and if it is still too big
     * only the scalar answers remain.
     *
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public static function capped(array $evidence): array
    {
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false && strlen($json) <= self::MAX_EVIDENCE_BYTES) {
            return $evidence;
        }
        $small = [];
        foreach ($evidence as $key => $value) {
            if (is_scalar($value)) {
                $small[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
            }
        }
        $small['note'] = 'Some of the work was too large to keep.';

        return $small;
    }
}
