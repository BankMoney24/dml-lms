<?php

declare(strict_types=1);

namespace App\Modules\Learning;

/**
 * Course completion rule (ASM-004). Every required (mandatory) lesson is
 * complete; quiz lessons count as complete once an attempt meets that quiz's
 * pass mark. A course may also ask for an average across its required quizzes
 * (each quiz's best score), set per course (ADR 0032).
 */
final class CompletionRule
{
    /**
     * @param list<int> $mandatoryLessonIds
     * @param list<int> $completedLessonIds
     */
    public function isComplete(array $mandatoryLessonIds, array $completedLessonIds, ?int $minAverage = null, ?int $quizAverage = null): bool
    {
        if ($mandatoryLessonIds === []) {
            return false;
        }
        if (array_diff($mandatoryLessonIds, $completedLessonIds) !== []) {
            return false;
        }

        // A course without required quizzes has no average to meet.
        return $minAverage === null || $quizAverage === null || $quizAverage >= $minAverage;
    }
}
