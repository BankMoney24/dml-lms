<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/** The marked outcome of one lab session. */
final class LabResult
{
    /**
     * @param list<array{label: string, ok: bool, detail: string}> $feedback one line per part of the task
     * @param array<string, mixed>                               $evidence what is kept: the learner's answers and work, not the answer key
     */
    public function __construct(
        public readonly int $score,
        public readonly array $feedback,
        public readonly array $evidence,
    ) {
    }

    /**
     * Builds a result from parts worth a number of marks each.
     *
     * @param list<array{label: string, ok: bool, detail: string, marks: int}> $parts
     * @param array<string, mixed>                                            $evidence
     */
    public static function fromParts(array $parts, array $evidence): self
    {
        $total = array_sum(array_column($parts, 'marks'));
        $earned = array_sum(array_map(static fn (array $p): int => $p['ok'] ? $p['marks'] : 0, $parts));

        return new self(
            $total === 0 ? 0 : (int) floor($earned * 100 / $total),
            array_map(static fn (array $p): array => ['label' => $p['label'], 'ok' => $p['ok'], 'detail' => $p['detail']], $parts),
            $evidence,
        );
    }
}
