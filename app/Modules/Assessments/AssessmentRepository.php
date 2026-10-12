<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

use App\Core\Clock;
use App\Core\Database\Connection;

final class AssessmentRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @return array{id: int, pass_threshold: int, max_attempts: ?int}|null */
    public function findForLesson(int $tenantId, int $lessonId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, pass_threshold, max_attempts FROM assessments WHERE tenant_id = ? AND lesson_id = ?',
            [$tenantId, $lessonId],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'pass_threshold' => (int) $row['pass_threshold'],
            'max_attempts' => $row['max_attempts'] === null ? null : (int) $row['max_attempts'],
        ];
    }

    public function create(int $tenantId, int $lessonId, int $passThreshold, ?int $maxAttempts): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO assessments (tenant_id, lesson_id, pass_threshold, max_attempts, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $lessonId, $passThreshold, $maxAttempts, $now, $now],
        );
    }

    /**
     * Questions with options. Includes the correct flag, so callers rendering
     * to learners must use QuizQuestion::forLearner() views only.
     *
     * @return list<QuizQuestion>
     */
    public function questions(int $tenantId, int $assessmentId): array
    {
        $questionRows = $this->db->fetchAll(
            'SELECT id, prompt FROM questions WHERE tenant_id = ? AND assessment_id = ? ORDER BY position, id',
            [$tenantId, $assessmentId],
        );
        if ($questionRows === []) {
            return [];
        }
        $optionRows = $this->db->fetchAll(
            'SELECT o.id, o.question_id, o.label, o.is_correct FROM question_options o
             JOIN questions q ON q.tenant_id = o.tenant_id AND q.id = o.question_id
             WHERE o.tenant_id = ? AND q.assessment_id = ? ORDER BY o.position, o.id',
            [$tenantId, $assessmentId],
        );
        $options = [];
        foreach ($optionRows as $row) {
            $options[(int) $row['question_id']][] = new QuizOption((int) $row['id'], (string) $row['label'], (bool) $row['is_correct']);
        }

        return array_map(
            static fn (array $q): QuizQuestion => new QuizQuestion((int) $q['id'], (string) $q['prompt'], $options[(int) $q['id']] ?? []),
            $questionRows,
        );
    }

    /** @param list<string> $optionLabels */
    public function addQuestion(int $tenantId, int $assessmentId, string $prompt, array $optionLabels, int $correctIndex): int
    {
        return $this->db->transaction(function () use ($tenantId, $assessmentId, $prompt, $optionLabels, $correctIndex): int {
            $position = (int) $this->db->fetchValue(
                'SELECT COALESCE(MAX(position), 0) + 1 FROM questions WHERE tenant_id = ? AND assessment_id = ?',
                [$tenantId, $assessmentId],
            );
            $questionId = $this->db->insert(
                'INSERT INTO questions (tenant_id, assessment_id, prompt, position, created_at) VALUES (?, ?, ?, ?, ?)',
                [$tenantId, $assessmentId, $prompt, $position, $this->clock->sqlNow()],
            );
            foreach (array_values($optionLabels) as $i => $label) {
                $this->db->insert(
                    'INSERT INTO question_options (tenant_id, question_id, label, is_correct, position) VALUES (?, ?, ?, ?, ?)',
                    [$tenantId, $questionId, $label, $i === $correctIndex ? 1 : 0, $i + 1],
                );
            }

            return $questionId;
        });
    }

    public function questionCount(int $tenantId, int $assessmentId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM questions WHERE tenant_id = ? AND assessment_id = ?',
            [$tenantId, $assessmentId],
        );
    }
}
