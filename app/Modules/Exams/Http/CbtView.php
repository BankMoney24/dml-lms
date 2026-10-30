<?php

declare(strict_types=1);

namespace App\Modules\Exams\Http;

use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Cbt\CbtAttempt;
use App\Modules\Tenancy\Tenant;

/** What a marked attempt looks like question by question, for candidates and teachers (ADR 0042). */
final class CbtView
{
    /**
     * @return array{attempt: CbtAttempt, sections: list<array{label: string, items: list<array{n: int, question: \App\Modules\Exams\Bank\BankQuestion, choice: ?int}>}>,
     *               passages: array<int, \App\Modules\Exams\Bank\Passage>}
     */
    public static function review(Tenant $tenant, CbtAttempt $attempt, BankRepository $banks): array
    {
        $questions = $banks->questionsByIds($tenant->id, $attempt->questionIds());
        $sections = [];
        $n = 0;
        $passageIds = [];
        foreach ($attempt->paper['sections'] as $s) {
            $items = [];
            foreach ($s['questions'] as $pq) {
                $n++;
                $q = $questions[$pq['id']] ?? null;
                if ($q === null) {
                    continue;
                }
                if ($q->passageId !== null) {
                    $passageIds[] = $q->passageId;
                }
                $items[] = ['n' => $n, 'question' => $q, 'choice' => $attempt->answers[$q->id] ?? null];
            }
            $sections[] = ['label' => $s['label'], 'items' => $items];
        }

        return ['attempt' => $attempt, 'sections' => $sections, 'passages' => $banks->passagesByIds($tenant->id, $passageIds)];
    }
}
