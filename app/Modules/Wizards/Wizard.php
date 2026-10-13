<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

use App\Modules\Identity\CurrentUser;

/**
 * A guided setup journey (PRD §6.3). The engine (WizardController) handles
 * progress, Back / Continue / Skip / Save and exit, saving answers on the
 * server after every step, resuming, and the review step; a definition only
 * says what the steps are, who may run it, what must be in place before it
 * can finish, and what finishing does.
 */
interface Wizard
{
    /** URL segment: /setup/{id}. */
    public function id(): string;

    public function title(): string;

    /** Checked on every request; the engine answers "not found" otherwise. */
    public function mayRun(CurrentUser $user): bool;

    /** @return list<WizardStep> in order; the last one is the review step */
    public function steps(): array;

    /**
     * Whether a new run starts with every step already answered (the
     * institution is set up, so this run edits its settings).
     */
    public function startsComplete(WizardContext $ctx): bool;

    /**
     * What is missing or worth a second look, for the review step. Blocking
     * problems stop the finish button.
     *
     * @param array<string, array<string, mixed>> $data every step's answers
     * @return list<array{step: string, message: string, blocking: bool}>
     */
    public function problems(WizardContext $ctx, array $data): array;

    /** Wording of the finish button: "Launch your school", "Save changes". */
    public function finishLabel(WizardContext $ctx): string;

    /**
     * Applies the answers. Runs only after the review step is confirmed.
     *
     * @param array<string, array<string, mixed>> $data every step's answers
     * @return string where to send the person next
     */
    public function finish(WizardContext $ctx, array $data): string;

    /**
     * Called when the person throws away an unfinished run, so the wizard
     * can remove anything it stored on the way (an uploaded logo, say).
     *
     * @param array<string, array<string, mixed>> $data
     */
    public function discarded(WizardContext $ctx, array $data): void;

    /** Where "Save and exit" and "Discard" lead. */
    public function exitPath(): string;
}
