<?php

declare(strict_types=1);

namespace App\Modules\Wizards\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Audit\AuditLog;
use App\Modules\Wizards\Wizard;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardRegistry;
use App\Modules\Wizards\WizardRun;
use App\Modules\Wizards\WizardRunRepository;
use App\Modules\Wizards\WizardStep;

/**
 * The wizard engine's screens, the same for every wizard:
 *
 *   GET  /setup/{wizard}            resume where the institution left off
 *   GET  /setup/{wizard}/{step}     show a step (only once the steps before it are answered)
 *   POST /setup/{wizard}/{step}     action = continue | back | skip | save | finish
 *   POST /setup/{wizard}/discard    throw away an unfinished run
 *
 * Answers are saved on the server after every step, shared by everyone
 * allowed to run the wizard, and applied only by "finish" on the review step.
 */
final class WizardController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly WizardRegistry $wizards,
        private readonly WizardRunRepository $runs,
        private readonly AuditLog $audit,
    ) {
    }

    public function resume(Request $request): Response
    {
        [$wizard, $ctx] = $this->open($request);
        $run = $this->runFor($wizard, $ctx);

        return $this->redirectTo($wizard, $this->reachable($wizard, $run, $run->current));
    }

    public function show(Request $request): Response
    {
        [$wizard, $ctx] = $this->open($request);
        $step = $this->step($wizard, $request->routeParam('step'));
        $run = $this->runFor($wizard, $ctx);
        $allowed = $this->reachable($wizard, $run, $step->key());
        if ($allowed !== $step->key()) {
            return $this->redirectTo($wizard, $allowed);
        }
        // Only remember the position of a run someone has started answering:
        // opening a step to look must not leave "unsaved changes" behind.
        if ($run->updatedAt !== null && $run->current !== $step->key()) {
            $run->current = $step->key();
            $this->runs->save($ctx->tenant->id, $wizard->id(), $run, $ctx->user->user->id);
        }

        return $this->render($wizard, $ctx, $run, $step, $this->valuesFor($step, $ctx, $run));
    }

    public function submit(Request $request): Response
    {
        [$wizard, $ctx] = $this->open($request);
        $step = $this->step($wizard, $request->routeParam('step'));
        $run = $this->runFor($wizard, $ctx);
        if ($this->reachable($wizard, $run, $step->key()) !== $step->key()) {
            return $this->redirectTo($wizard, $this->reachable($wizard, $run, $step->key()));
        }
        $action = $request->input('action', 'continue') ?? 'continue';
        $tenantId = $ctx->tenant->id;
        $userId = $ctx->user->user->id;

        if ($step->isReview()) {
            return match ($action) {
                'finish' => $this->finish($wizard, $ctx, $run, $step),
                'back' => $this->move($wizard, $ctx, $run, -1, $step),
                default => $this->redirectTo($wizard, $step->key()),
            };
        }

        if ($action === 'skip' && $step->optional()) {
            $run->skip($step->key());

            return $this->move($wizard, $ctx, $run, 1, $step);
        }

        $raw = $step->read($request);
        try {
            $values = $step->accept($ctx, $raw, $run->data);
        } catch (ValidationException $e) {
            if ($action === 'back') {
                // Going back never forces the person to fix this page first.
                return $this->move($wizard, $ctx, $run, -1, $step);
            }

            return $this->render($wizard, $ctx, $run, $step, $raw + $this->valuesFor($step, $ctx, $run), $e->errors, 422);
        }

        if ($action === 'back') {
            $run->keep($step->key(), $values);

            return $this->move($wizard, $ctx, $run, -1, $step);
        }
        $run->answer($step->key(), $values);
        if ($action === 'save') {
            $this->runs->save($tenantId, $wizard->id(), $run, $userId);
            $this->session->flash('success', 'Your answers are saved. Pick up where you left off at any time.');

            return Response::redirect($wizard->exitPath());
        }
        if ($request->input('return') === 'review' && $this->allHandled($wizard, $run)) {
            $review = $this->reviewStep($wizard);
            $run->current = $review->key();
            $this->runs->save($tenantId, $wizard->id(), $run, $userId);

            return $this->redirectTo($wizard, $review->key());
        }

        return $this->move($wizard, $ctx, $run, 1, $step);
    }

    public function discard(Request $request): Response
    {
        [$wizard, $ctx] = $this->open($request);
        $run = $this->runs->find($ctx->tenant->id, $wizard->id());
        if ($run !== null) {
            $wizard->discarded($ctx, $run->data);
            $this->runs->delete($ctx->tenant->id, $wizard->id());
            $this->session->flash('success', 'Unsaved setup changes were discarded. Nothing about your school changed.');
        }

        return Response::redirect($wizard->exitPath());
    }

    private function finish(Wizard $wizard, WizardContext $ctx, WizardRun $run, WizardStep $review): Response
    {
        $data = $this->effectiveData($wizard, $ctx, $run);
        $blocking = array_filter($wizard->problems($ctx, $data), static fn (array $p): bool => $p['blocking']);
        if ($blocking !== []) {
            return $this->render($wizard, $ctx, $run, $review, [], ['finish' => 'Fix the problems marked below first.'], 422);
        }

        $next = $wizard->finish($ctx, $data);
        $this->runs->delete($ctx->tenant->id, $wizard->id());
        // Which steps were answered, never what was typed into them (PRD §6.3).
        $this->audit->record($ctx->tenant->id, $ctx->user->user->id, 'wizard.finished', null, null, [
            'wizard' => $wizard->id(),
            'answered' => implode(',', $run->done),
            'skipped' => implode(',', $run->skipped),
        ], $ctx->request->clientIp);

        return Response::redirect($next);
    }

    /** Saves and goes one step forward or back. */
    private function move(Wizard $wizard, WizardContext $ctx, WizardRun $run, int $direction, WizardStep $from): Response
    {
        $keys = array_map(static fn (WizardStep $s): string => $s->key(), $wizard->steps());
        $index = (int) array_search($from->key(), $keys, true);
        $target = $keys[max(0, min(count($keys) - 1, $index + $direction))];
        $run->current = $target;
        $this->runs->save($ctx->tenant->id, $wizard->id(), $run, $ctx->user->user->id);

        return $this->redirectTo($wizard, $this->reachable($wizard, $run, $target));
    }

    /**
     * The step the person may see when asking for $wanted: every step before
     * it must be answered (or, if optional, skipped). Otherwise the first
     * step that is not.
     */
    private function reachable(Wizard $wizard, WizardRun $run, string $wanted): string
    {
        foreach ($wizard->steps() as $step) {
            if ($step->key() === $wanted) {
                return $wanted;
            }
            if (!$run->isHandled($step->key())) {
                return $step->key();
            }
        }

        return $wizard->steps()[0]->key();
    }

    private function allHandled(Wizard $wizard, WizardRun $run): bool
    {
        foreach ($wizard->steps() as $step) {
            if (!$step->isReview() && !$run->isHandled($step->key())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every step's answers, falling back to what the institution already
     * has for steps not touched in this run.
     *
     * @return array<string, array<string, mixed>>
     */
    private function effectiveData(Wizard $wizard, WizardContext $ctx, WizardRun $run): array
    {
        $data = $run->data;
        foreach ($wizard->steps() as $step) {
            if (!isset($data[$step->key()]) && !$step->isReview()) {
                $data[$step->key()] = $step->initial($ctx, $data);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function valuesFor(WizardStep $step, WizardContext $ctx, WizardRun $run): array
    {
        return $run->data[$step->key()] ?? $step->initial($ctx, $run->data);
    }

    private function runFor(Wizard $wizard, WizardContext $ctx): WizardRun
    {
        $run = $this->runs->find($ctx->tenant->id, $wizard->id());
        if ($run !== null) {
            return $run;
        }
        $steps = $wizard->steps();
        $done = [];
        if ($wizard->startsComplete($ctx)) {
            foreach ($steps as $step) {
                if (!$step->isReview()) {
                    $done[] = $step->key();
                }
            }
        }
        // Not saved yet: the first answer (or Save and exit) stores it.
        return new WizardRun([], $done, [], $steps[0]->key());
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function render(Wizard $wizard, WizardContext $ctx, WizardRun $run, WizardStep $step, array $values, array $errors = [], int $status = 200): Response
    {
        $steps = $wizard->steps();
        $position = 0;
        foreach ($steps as $i => $s) {
            if ($s->key() === $step->key()) {
                $position = $i;
            }
        }
        $review = null;
        if ($step->isReview()) {
            $data = $this->effectiveData($wizard, $ctx, $run);
            $review = [
                'sections' => array_map(static fn (WizardStep $s): array => [
                    'step' => $s,
                    'skipped' => in_array($s->key(), $run->skipped, true),
                    'lines' => in_array($s->key(), $run->skipped, true) ? [] : $s->summary($ctx, $data[$s->key()] ?? []),
                ], array_values(array_filter($steps, static fn (WizardStep $s): bool => !$s->isReview() && $s->inReview()))),
                'problems' => $wizard->problems($ctx, $data),
                'finishLabel' => $wizard->finishLabel($ctx),
            ];
        }

        $html = $this->view->render('wizards/frame', [
            'pageTitle' => $step->title() . ' – ' . $wizard->title(),
            'wizard' => $wizard,
            'steps' => $steps,
            'step' => $step,
            'position' => $position,
            'run' => $run,
            'reachable' => fn (string $key): bool => $this->reachable($wizard, $run, $key) === $key,
            'values' => $values,
            'errors' => $errors,
            'review' => $review,
            'returnToReview' => $ctx->request->query('return') === 'review' || $ctx->request->input('return') === 'review',
            'extra' => $step->viewData($ctx, $values, $run->data),
        ]);

        return Response::html($html, $status);
    }

    /** @return array{0: Wizard, 1: WizardContext} */
    private function open(Request $request): array
    {
        $wizard = $this->wizards->find($request->routeParam('wizard')) ?? throw HttpException::notFound();
        $user = RequestContext::user($request);
        if (!$wizard->mayRun($user)) {
            throw HttpException::notFound();
        }

        return [$wizard, new WizardContext(RequestContext::tenant($request), $user, $request)];
    }

    private function step(Wizard $wizard, string $key): WizardStep
    {
        foreach ($wizard->steps() as $step) {
            if ($step->key() === $key) {
                return $step;
            }
        }
        throw HttpException::notFound();
    }

    private function reviewStep(Wizard $wizard): WizardStep
    {
        $steps = $wizard->steps();

        return $steps[count($steps) - 1];
    }

    private function redirectTo(Wizard $wizard, string $step): Response
    {
        return Response::redirect('/setup/' . $wizard->id() . '/' . $step);
    }
}
