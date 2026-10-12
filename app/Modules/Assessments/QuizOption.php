<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

final class QuizOption
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly bool $correct,
    ) {
    }
}
