<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

/** Pure server-side scoring of a multiple-choice attempt (ASM-001). */
final class QuizScorer
{
    /**
     * @param list<QuizQuestion>  $questions
     * @param array<int, int>     $answers question id => chosen option id
     * @return array{correct: int, total: int, percent: int, passed: bool, answers: array<int, int>}
     */
    public function score(array $questions, array $answers, int $passThreshold): array
    {
        $correct = 0;
        $recorded = [];
        foreach ($questions as $question) {
            $chosen = $answers[$question->id] ?? null;
            $validIds = array_map(static fn (QuizOption $o): int => $o->id, $question->options);
            // Ignore answers that do not belong to this question (tampered or stale forms).
            if ($chosen === null || !in_array($chosen, $validIds, true)) {
                continue;
            }
            $recorded[$question->id] = $chosen;
            if ($chosen === $question->correctOptionId()) {
                $correct++;
            }
        }

        $total = count($questions);
        // Floor, so a learner is never rounded up across the pass line.
        $percent = $total === 0 ? 0 : intdiv($correct * 100, $total);

        return [
            'correct' => $correct,
            'total' => $total,
            'percent' => $percent,
            'passed' => $total > 0 && $percent >= $passThreshold,
            'answers' => $recorded,
        ];
    }
}
