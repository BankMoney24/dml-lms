<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Tenancy\Tenant;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

final class StructureStep extends WizardStep
{
    private const MAX_LEVELS = 30;

    /** Suggestions by institution type: [calendar, levels, word for a group of learners]. */
    private const SUGGESTIONS = [
        'school' => ['terms', ['JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3'], 'class'],
        'university' => ['semesters', ['100 Level', '200 Level', '300 Level', '400 Level', '500 Level'], 'group'],
        'polytechnic' => ['semesters', ['ND 1', 'ND 2', 'HND 1', 'HND 2'], 'group'],
        'training_centre' => ['flexible', [], 'cohort'],
        'community' => ['flexible', [], 'group'],
        'other' => ['flexible', [], 'group'],
    ];

    public function key(): string
    {
        return 'structure';
    }

    public function title(): string
    {
        return 'Your academic year';
    }

    public function why(): string
    {
        return 'This is saved with your school profile. Classes or cohorts you create later use these levels and this name.';
    }

    public function optional(): bool
    {
        return true;
    }

    public function view(): string
    {
        return 'school/structure';
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        $tenant = $ctx->tenant;
        $kind = (string) ($all['type']['kind'] ?? $tenant->kind ?? 'other');
        [$calendar, $levels, $term] = self::SUGGESTIONS[$kind] ?? self::SUGGESTIONS['other'];
        if ($tenant->calendar !== null) {
            return ['calendar' => $tenant->calendar, 'levels' => implode("\n", $tenant->levels), 'group_term' => $tenant->groupTerm ?? $term, 'learner_term' => $tenant->learnerTerm];
        }

        return ['calendar' => $calendar, 'levels' => implode("\n", $levels), 'group_term' => $term, 'learner_term' => $tenant->learnerTerm];
    }

    public function read(Request $request): array
    {
        return [
            'calendar' => $request->input('calendar', '') ?? '',
            'levels' => $request->input('levels', '') ?? '',
            'group_term' => $request->input('group_term', '') ?? '',
            'learner_term' => $request->input('learner_term', '') ?? '',
        ];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $errors = [];
        $calendar = (string) ($raw['calendar'] ?? '');
        if (!isset(Tenant::CALENDARS[$calendar])) {
            $errors['calendar'] = 'Choose how your year is organised.';
        }
        $term = (string) ($raw['group_term'] ?? '');
        if ($term !== '' && !isset(Tenant::GROUP_TERMS[$term])) {
            $errors['group_term'] = 'Choose what you call a group of learners.';
        }
        $learner = (string) ($raw['learner_term'] ?? '');
        if ($learner !== '' && !isset(Tenant::LEARNER_TERMS[$learner])) {
            $errors['learner_term'] = 'Choose what you call the people who learn here.';
        }
        $levels = self::levels((string) ($raw['levels'] ?? ''));
        if (count($levels) > self::MAX_LEVELS) {
            $errors['levels'] = sprintf('List up to %d classes or levels.', self::MAX_LEVELS);
        }
        foreach ($levels as $level) {
            if (mb_strlen($level) > 60) {
                $errors['levels'] = sprintf('"%s…" is too long. Keep each class or level under 60 characters.', mb_substr($level, 0, 20));
                break;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['calendar' => $calendar, 'levels' => implode("\n", $levels), 'group_term' => $term, 'learner_term' => $learner === '' ? 'learner' : $learner];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        $levels = self::levels((string) ($data['levels'] ?? ''));

        return [
            ['Year organised in', Tenant::CALENDARS[(string) ($data['calendar'] ?? '')] ?? 'Not set'],
            ['Classes or levels', $levels === [] ? 'None listed' : implode(', ', $levels)],
            ['Groups of learners are called', ucfirst(Tenant::GROUP_TERMS[(string) ($data['group_term'] ?? '')][1] ?? $ctx->tenant->groupWord(true))],
            ['People who learn here are called', ucfirst(Tenant::LEARNER_TERMS[(string) ($data['learner_term'] ?? '')][1] ?? $ctx->tenant->learnerWord(true))],
        ];
    }

    /** @return list<string> one per line, trimmed, blanks and repeats removed */
    public static function levels(string $text): array
    {
        $levels = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '' && !in_array($line, $levels, true)) {
                $levels[] = $line;
            }
        }

        return $levels;
    }
}
