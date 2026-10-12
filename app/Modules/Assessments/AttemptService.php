<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Lesson;
use App\Modules\Learning\Enrolment;
use App\Modules\Learning\LearningService;

final class AttemptService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AssessmentRepository $assessments,
        private readonly AttemptRepository $attempts,
        private readonly QuizScorer $scorer,
        private readonly LearningService $learning,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Scores and records an attempt. Re-sending the same idempotency key
     * (double click, retry after a dropped connection) returns the original
     * attempt instead of recording a second one.
     *
     * @param array<string, string> $rawAnswers question id => option id, as submitted
     * @return int attempt id
     */
    public function submit(Enrolment $enrolment, Lesson $lesson, array $rawAnswers, string $idempotencyKey): int
    {
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw HttpException::badRequest('This quiz form is out of date. Reload the page and try again.');
        }
        if ($lesson->tenantId !== $enrolment->tenantId || $lesson->courseId !== $enrolment->courseId || !$lesson->isQuiz()) {
            throw HttpException::notFound();
        }
        $assessment = $this->assessments->findForLesson($lesson->tenantId, $lesson->id);
        if ($assessment === null) {
            throw HttpException::notFound();
        }

        $answers = [];
        foreach ($rawAnswers as $questionId => $optionId) {
            if (ctype_digit((string) $questionId) && ctype_digit($optionId)) {
                $answers[(int) $questionId] = (int) $optionId;
            }
        }

        return $this->db->transaction(function () use ($enrolment, $lesson, $assessment, $answers, $idempotencyKey): int {
            // Serialise concurrent submissions for this learner and course.
            $this->db->fetchOne('SELECT id FROM enrolments WHERE tenant_id = ? AND id = ? FOR UPDATE', [$enrolment->tenantId, $enrolment->id]);

            $existing = $this->attempts->findIdByIdempotencyKey($enrolment->tenantId, $enrolment->userId, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }

            $used = $this->attempts->countFor($enrolment->tenantId, $assessment['id'], $enrolment->userId);
            if ($assessment['max_attempts'] !== null && $used >= $assessment['max_attempts']) {
                throw new HttpException(409, 'You have used all the attempts allowed for this quiz.');
            }

            $questions = $this->assessments->questions($enrolment->tenantId, $assessment['id']);
            if ($questions === []) {
                throw new HttpException(409, 'This quiz has no questions yet.');
            }
            $score = $this->scorer->score($questions, $answers, $assessment['pass_threshold']);

            $attemptId = $this->attempts->insert(
                $enrolment->tenantId,
                $assessment['id'],
                $enrolment->id,
                $enrolment->userId,
                $idempotencyKey,
                $score,
            );
            $this->audit->record($enrolment->tenantId, $enrolment->userId, 'assessment.attempt_submitted', 'quiz_attempt', $attemptId, [
                'score_percent' => $score['percent'],
                'passed' => $score['passed'],
            ]);

            if ($score['passed']) {
                $this->learning->recordLessonCompleted($enrolment, $lesson);
            }

            return $attemptId;
        });
    }
}
