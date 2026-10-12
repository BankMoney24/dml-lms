<?php

declare(strict_types=1);

namespace App\Modules\Learning;

/**
 * Course completion rule (ASM-004). The default rule: every mandatory lesson
 * is complete. Quiz lessons only count as complete once an attempt meets that
 * quiz's pass threshold, so the score requirement is carried by each quiz's
 * configurable threshold rather than a hardcoded course percentage.
 */
final class CompletionRule
{
    /**
     * @param list<int> $mandatoryLessonIds
     * @param list<int> $completedLessonIds
     */
    public function isComplete(array $mandatoryLessonIds, array $completedLessonIds): bool
    {
        if ($mandatoryLessonIds === []) {
            return false;
        }

        return array_diff($mandatoryLessonIds, $completedLessonIds) === [];
    }
}
