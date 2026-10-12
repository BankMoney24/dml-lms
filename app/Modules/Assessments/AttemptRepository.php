<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

use App\Core\Clock;
use App\Core\Database\Connection;

final class AttemptRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function findIdByIdempotencyKey(int $tenantId, int $userId, string $key): ?int
    {
        $id = $this->db->fetchValue(
            'SELECT id FROM quiz_attempts WHERE tenant_id = ? AND user_id = ? AND idempotency_key = ?',
            [$tenantId, $userId, $key],
        );

        return $id === null ? null : (int) $id;
    }

    public function countFor(int $tenantId, int $assessmentId, int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM quiz_attempts WHERE tenant_id = ? AND assessment_id = ? AND user_id = ?',
            [$tenantId, $assessmentId, $userId],
        );
    }

    /**
     * @param array{correct: int, total: int, percent: int, passed: bool, answers: array<int, int>} $score
     */
    public function insert(int $tenantId, int $assessmentId, int $enrolmentId, int $userId, string $key, array $score): int
    {
        return $this->db->insert(
            'INSERT INTO quiz_attempts (tenant_id, assessment_id, enrolment_id, user_id, idempotency_key, score_percent,
                                        correct_count, question_count, passed, answers, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId, $assessmentId, $enrolmentId, $userId, $key, $score['percent'],
                $score['correct'], $score['total'], $score['passed'] ? 1 : 0,
                json_encode($score['answers'], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                $this->clock->sqlNow(),
            ],
        );
    }

    /**
     * Only returns the attempt to its owner within the tenant.
     *
     * @return array{id: int, assessment_id: int, score_percent: int, correct_count: int, question_count: int, passed: bool, submitted_at: string, answers: array<int, int>}|null
     */
    public function findForUser(int $tenantId, int $userId, int $attemptId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, assessment_id, score_percent, correct_count, question_count, passed, submitted_at, answers
             FROM quiz_attempts WHERE tenant_id = ? AND user_id = ? AND id = ?',
            [$tenantId, $userId, $attemptId],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'assessment_id' => (int) $row['assessment_id'],
            'score_percent' => (int) $row['score_percent'],
            'correct_count' => (int) $row['correct_count'],
            'question_count' => (int) $row['question_count'],
            'passed' => (bool) $row['passed'],
            'submitted_at' => (string) $row['submitted_at'],
            'answers' => self::decodeAnswers((string) $row['answers']),
        ];
    }

    /** Whether this learner has ever passed the quiz. */
    public function hasPassed(int $tenantId, int $assessmentId, int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM quiz_attempts WHERE tenant_id = ? AND assessment_id = ? AND user_id = ? AND passed = 1 LIMIT 1',
            [$tenantId, $assessmentId, $userId],
        ) !== null;
    }

    /** @return array<int, int> question id => chosen option id */
    private static function decodeAnswers(string $json): array
    {
        $decoded = json_decode($json, true);
        $answers = [];
        if (is_array($decoded)) {
            foreach ($decoded as $question => $option) {
                if (is_numeric($question) && is_numeric($option)) {
                    $answers[(int) $question] = (int) $option;
                }
            }
        }

        return $answers;
    }

    public function bestScore(int $tenantId, int $assessmentId, int $userId): ?int
    {
        $best = $this->db->fetchValue(
            'SELECT MAX(score_percent) FROM quiz_attempts WHERE tenant_id = ? AND assessment_id = ? AND user_id = ?',
            [$tenantId, $assessmentId, $userId],
        );

        return $best === null ? null : (int) $best;
    }
}
