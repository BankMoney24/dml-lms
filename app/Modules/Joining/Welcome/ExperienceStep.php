<?php

declare(strict_types=1);

namespace App\Modules\Joining\Welcome;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Joining\LearnerProfile;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

final class ExperienceStep extends WizardStep
{
    public function __construct(private readonly \App\Modules\Joining\ProfileRepository $profiles)
    {
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        $p = $this->profiles->find($ctx->tenant->id, $ctx->user->user->id);

        if ($p === null) {
            return ['experience' => '', 'github' => '', 'portfolio' => ''];
        }

        return ['experience' => $p->experience ?? '', 'github' => $p->github ?? '', 'portfolio' => $p->portfolio ?? ''];
    }

    public function key(): string
    {
        return 'experience';
    }

    public function title(): string
    {
        return 'What you already know';
    }

    public function why(): string
    {
        return 'So beginners start at the beginning and experienced people skip ahead.';
    }

    public function view(): string
    {
        return 'welcome/experience';
    }

    public function read(Request $request): array
    {
        return [
            'experience' => $request->input('experience', '') ?? '',
            'github' => $request->input('github', '') ?? '',
            'portfolio' => $request->input('portfolio', '') ?? '',
        ];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $experience = (string) ($raw['experience'] ?? '');
        $github = LearnerProfile::githubHandle((string) ($raw['github'] ?? ''));
        $portfolio = trim((string) ($raw['portfolio'] ?? ''));
        $errors = [];
        if (!isset(LearnerProfile::EXPERIENCE[$experience])) {
            $errors['experience'] = 'Choose the one that fits best.';
        }
        if ($github !== '' && !LearnerProfile::validGithub($github)) {
            $errors['github'] = 'Enter your GitHub username, for example ada-lovelace, or leave it empty.';
        }
        if ($portfolio !== '' && preg_match('#^https://[^\s"<>]{3,240}$#', $portfolio) !== 1) {
            $errors['portfolio'] = 'Paste a full link starting with https://, or leave it empty.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['experience' => $experience, 'github' => $github, 'portfolio' => $portfolio];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $rows = [['Experience', LearnerProfile::EXPERIENCE[(string) ($data['experience'] ?? '')][0] ?? 'Not chosen']];
        if (($data['github'] ?? '') !== '') {
            $rows[] = ['GitHub', 'github.com/' . $data['github']];
        }
        if (($data['portfolio'] ?? '') !== '') {
            $rows[] = ['Portfolio', (string) $data['portfolio']];
        }

        return $rows;
    }
}
