<?php

declare(strict_types=1);

namespace App\Modules\Joining\Welcome;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Joining\LearnerProfile;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

final class RhythmStep extends WizardStep
{
    public function __construct(private readonly \App\Modules\Joining\ProfileRepository $profiles)
    {
    }

    public function key(): string
    {
        return 'rhythm';
    }

    public function title(): string
    {
        return 'Your weekly goal';
    }

    public function why(): string
    {
        return 'A small, regular habit beats a long session once a month. Your home page shows how the week is going.';
    }

    public function view(): string
    {
        return 'welcome/rhythm';
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        $p = $this->profiles->find($ctx->tenant->id, $ctx->user->user->id);

        return ['weekly_goal' => $p !== null && $p->weeklyGoal !== null ? $p->weeklyGoal : 3];
    }

    public function read(Request $request): array
    {
        return ['weekly_goal' => (int) ($request->input('weekly_goal', '0') ?? '0')];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $goal = (int) ($raw['weekly_goal'] ?? 0);
        if (!in_array($goal, LearnerProfile::WEEKLY_GOALS, true)) {
            throw ValidationException::single('weekly_goal', 'Choose how many days a week you want to learn.');
        }

        return ['weekly_goal' => $goal];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $goal = (int) ($data['weekly_goal'] ?? 0);

        return [['Weekly goal', $goal === 7 ? 'Every day' : $goal . ($goal === 1 ? ' day' : ' days') . ' a week']];
    }
}
