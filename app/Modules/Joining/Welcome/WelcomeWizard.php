<?php

declare(strict_types=1);

namespace App\Modules\Joining\Welcome;

use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Joining\LearnerProfile;
use App\Modules\Joining\ProfileRepository;
use App\Modules\Wizards\ReviewStep;
use App\Modules\Wizards\Wizard;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

/**
 * A learner's first minute (ADR 0020): what they want to learn, how much
 * they already know (programmers and professionals can say so, and add their
 * GitHub or portfolio), and how often they want to study. The answers order
 * the course suggestions on their home page and set their weekly goal.
 * Each person has their own run.
 */
final class WelcomeWizard implements Wizard
{
    public const ID = 'welcome';

    public function __construct(private readonly ProfileRepository $profiles)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function runKey(WizardContext $ctx): string
    {
        return self::ID . ':' . $ctx->user->user->id;
    }

    public function title(): string
    {
        return 'Getting started';
    }

    public function mayRun(CurrentUser $user): bool
    {
        return $user->can(Permission::CourseLearn);
    }

    public function steps(): array
    {
        return [
            new GoalsStep($this->profiles),
            new ExperienceStep($this->profiles),
            new RhythmStep($this->profiles),
            new ReviewStep('Ready', 'Check your answers. You can change them later from your account page.'),
        ];
    }

    public function startsComplete(WizardContext $ctx): bool
    {
        return $this->profiles->find($ctx->tenant->id, $ctx->user->user->id)?->isOnboarded() ?? false;
    }

    public function problems(WizardContext $ctx, array $data): array
    {
        return [];
    }

    public function finishLabel(WizardContext $ctx): string
    {
        return 'Show my suggestions';
    }

    public function finish(WizardContext $ctx, array $data): string
    {
        $goals = array_values(array_filter((array) ($data['goals']['goals'] ?? []), static fn ($g): bool => is_string($g) && isset(LearnerProfile::GOALS[$g])));
        $experience = (string) ($data['experience']['experience'] ?? '');
        $weekly = (int) ($data['rhythm']['weekly_goal'] ?? 0);
        $github = (string) ($data['experience']['github'] ?? '');
        $portfolio = (string) ($data['experience']['portfolio'] ?? '');
        $this->profiles->saveOnboarding(
            $ctx->tenant->id,
            $ctx->user->user->id,
            $goals,
            isset(LearnerProfile::EXPERIENCE[$experience]) ? $experience : null,
            in_array($weekly, LearnerProfile::WEEKLY_GOALS, true) ? $weekly : null,
            $github !== '' ? $github : null,
            $portfolio !== '' ? $portfolio : null,
        );

        return ($data['goals']['teach'] ?? false) === true && !$ctx->user->can(Permission::CourseManage) ? '/join/teach?from=welcome' : '/?welcome=1';
    }

    public function discarded(WizardContext $ctx, array $data): void
    {
    }

    public function exitPath(): string
    {
        return '/';
    }
}
