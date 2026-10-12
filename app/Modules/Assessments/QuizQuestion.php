<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

final class QuizQuestion
{
    /** @param list<QuizOption> $options */
    public function __construct(
        public readonly int $id,
        public readonly string $prompt,
        public readonly array $options,
    ) {
    }

    public function correctOptionId(): ?int
    {
        foreach ($this->options as $option) {
            if ($option->correct) {
                return $option->id;
            }
        }

        return null;
    }

    /**
     * View for learners: option ids and labels only. The correct answer never
     * leaves the server before submission.
     *
     * @return array{id: int, prompt: string, options: list<array{id: int, label: string}>}
     */
    public function forLearner(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'options' => array_map(static fn (QuizOption $o): array => ['id' => $o->id, 'label' => $o->label], $this->options),
        ];
    }
}
