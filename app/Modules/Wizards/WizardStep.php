<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;

/**
 * One step of a wizard: what it asks, why, how its form is read and
 * checked, and how its answers read back on the review step.
 *
 * Form values travel as a plain array with the same keys in three places:
 * read() from the request, accept() into saved data, and the step's view
 * (which receives $values). On a validation error the view gets the raw
 * values back so nothing typed is lost.
 */
abstract class WizardStep
{
    /** URL segment and storage key: lowercase letters and hyphens. */
    abstract public function key(): string;

    /** Short name shown in the progress list ("Name and look"). */
    abstract public function title(): string;

    /** Template under resources/views/wizards/, without .php. */
    abstract public function view(): string;

    /** Why the information is needed, when that is not obvious (PRD §6.3). */
    public function why(): string
    {
        return '';
    }

    /** Optional steps get a Skip button and do not block finishing. */
    public function optional(): bool
    {
        return false;
    }

    /** The final step: summary of every answer and the button that applies them. */
    public function isReview(): bool
    {
        return false;
    }

    /** Whether the review step lists this step's answers (not for a welcome page). */
    public function inReview(): bool
    {
        return true;
    }

    /**
     * Starting values when nothing is saved for this step yet, usually what
     * the institution already has, so nothing is asked twice.
     *
     * @param array<string, array<string, mixed>> $all saved data of every step
     * @return array<string, mixed>
     */
    public function initial(WizardContext $ctx, array $all): array
    {
        return [];
    }

    /**
     * Raw values from the submitted form.
     *
     * @return array<string, mixed>
     */
    public function read(Request $request): array
    {
        return [];
    }

    /**
     * Checks raw values and returns what to save.
     *
     * @param array<string, mixed>                $raw
     * @param array<string, array<string, mixed>> $all saved data of every step
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        return $raw;
    }

    /**
     * Plain-language lines for the review step.
     *
     * @param array<string, mixed> $data
     * @return list<array{0: string, 1: string}> label, value
     */
    public function summary(WizardContext $ctx, array $data): array
    {
        return [];
    }

    /**
     * Extra variables for the step's view.
     *
     * @param array<string, mixed>                $values
     * @param array<string, array<string, mixed>> $all
     * @return array<string, mixed>
     */
    public function viewData(WizardContext $ctx, array $values, array $all): array
    {
        return [];
    }
}
