<?php

declare(strict_types=1);

namespace App\Modules\Exams\Cbt;

use Random\Randomizer;

/**
 * Draws one candidate's paper (ADR 0042). For each section it takes `count`
 * questions from the bank at random (all of them when the bank has no more).
 * With shuffling on, the order is random but questions that share a passage
 * stay together, where the first of them falls; with it off, they keep the
 * bank's order. Options are shuffled per question when asked.
 */
final class PaperBuilder
{
    public function __construct(private readonly Randomizer $random = new Randomizer())
    {
    }

    /**
     * @param list<array{id: int, passage: ?int, options: int}> $pool the bank's drawable questions, oldest first
     * @return list<array{id: int, perm: list<int>}>
     */
    public function section(array $pool, int $count, bool $shuffleQuestions, bool $shuffleOptions): array
    {
        if ($pool === []) {
            return [];
        }
        $picked = $pool;
        if ($count < count($pool)) {
            $picked = [];
            foreach ($this->random->pickArrayKeys($pool, max(1, $count)) as $k) {
                $picked[] = $pool[(int) $k];
            }
        }
        if ($shuffleQuestions) {
            /** @var list<array{id: int, passage: ?int, options: int}> $mixed */
            $mixed = array_values($this->random->shuffleArray($picked));
            $picked = $this->keepPassagesTogether($mixed);
        } else {
            usort($picked, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        }

        return array_map(fn (array $q): array => [
            'id' => $q['id'],
            'perm' => $shuffleOptions ? array_values($this->random->shuffleArray(range(0, max(0, $q['options'] - 1)))) : range(0, max(0, $q['options'] - 1)),
        ], array_values($picked));
    }

    /**
     * @param list<array{id: int, passage: ?int, options: int}> $questions
     * @return list<array{id: int, passage: ?int, options: int}>
     */
    private function keepPassagesTogether(array $questions): array
    {
        $groups = [];
        foreach ($questions as $q) {
            $key = $q['passage'] === null ? 'q' . $q['id'] : 'p' . $q['passage'];
            $groups[$key][] = $q;
        }
        $out = [];
        foreach ($groups as $group) {
            usort($group, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            array_push($out, ...$group);
        }

        return $out;
    }
}
