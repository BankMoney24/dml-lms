<?php

declare(strict_types=1);

namespace App\Modules\Joining\Welcome;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Joining\LearnerProfile;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

final class GoalsStep extends WizardStep
{
    public function __construct(private readonly \App\Modules\Joining\ProfileRepository $profiles)
    {
    }

    public function key(): string
    {
        return 'goals';
    }

    public function title(): string
    {
        return 'What you want to learn';
    }

    public function why(): string
    {
        return 'Choose up to three. We use them to suggest courses; nobody else sees them.';
    }

    public function view(): string
    {
        return 'welcome/goals';
    }

    public function read(Request $request): array
    {
        return ['goals' => array_values($request->inputArray('goals')), 'teach' => $request->input('teach') === '1'];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $goals = array_values(array_unique(array_filter((array) ($raw['goals'] ?? []), static fn ($g): bool => is_string($g) && isset(LearnerProfile::GOALS[$g]))));
        if ($goals === [] && ($raw['teach'] ?? false) !== true) {
            throw ValidationException::single('goals', 'Choose at least one thing you would like to learn.');
        }
        if (count($goals) > 3) {
            throw ValidationException::single('goals', 'Choose up to three, so the suggestions stay focused.');
        }

        return ['goals' => $goals, 'teach' => ($raw['teach'] ?? false) === true];
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        $p = $this->profiles->find($ctx->tenant->id, $ctx->user->user->id);

        return ['goals' => $p !== null ? $p->goals : [], 'teach' => false];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $labels = array_map(static fn (string $g): string => LearnerProfile::GOALS[$g][0], (array) ($data['goals'] ?? []));
        $rows = [['Learning', $labels === [] ? 'Nothing chosen' : implode(', ', $labels)]];
        if (($data['teach'] ?? false) === true) {
            $rows[] = ['Teaching', 'You would also like to teach here; you can ask the school next'];
        }

        return $rows;
    }
}
