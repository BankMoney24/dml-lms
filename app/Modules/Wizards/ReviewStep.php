<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

/** The last step of every wizard: a plain-language summary and the finish button. */
final class ReviewStep extends WizardStep
{
    public function __construct(private readonly string $title = 'Check and finish', private readonly string $why = '')
    {
    }

    public function key(): string
    {
        return 'review';
    }

    public function title(): string
    {
        return $this->title;
    }

    public function why(): string
    {
        return $this->why;
    }

    public function view(): string
    {
        return '_review';
    }

    public function isReview(): bool
    {
        return true;
    }
}
