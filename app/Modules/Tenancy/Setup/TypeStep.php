<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Tenancy\Tenant;
use App\Modules\Wizards\WizardContext;
use App\Modules\Wizards\WizardStep;

final class TypeStep extends WizardStep
{
    /** What each choice suggests on the next step. */
    public const HINTS = [
        'school' => 'Primary or secondary. Suggests terms and classes such as JSS 1 to SS 3.',
        'university' => 'Suggests semesters and 100 to 500 level.',
        'polytechnic' => 'Suggests semesters and ND 1 to HND 2.',
        'training_centre' => 'Short courses and cohorts, with no fixed academic year.',
        'community' => 'People learning for life and work, at their own pace: short practical courses and groups, no terms or levels.',
        'other' => 'Start with no fixed structure and set it up your way.',
    ];

    public function key(): string
    {
        return 'type';
    }

    public function title(): string
    {
        return 'Type of institution';
    }

    public function why(): string
    {
        return 'This only fills in sensible suggestions on the next step. You can change everything.';
    }

    public function view(): string
    {
        return 'school/type';
    }

    public function initial(WizardContext $ctx, array $all): array
    {
        return ['kind' => $ctx->tenant->kind ?? ''];
    }

    public function read(Request $request): array
    {
        return ['kind' => $request->input('kind', '') ?? ''];
    }

    public function accept(WizardContext $ctx, array $raw, array $all): array
    {
        $kind = (string) ($raw['kind'] ?? '');
        if (!isset(Tenant::KINDS[$kind])) {
            throw ValidationException::single('kind', 'Choose the type that fits best. "Other" is fine if none does.');
        }

        return ['kind' => $kind];
    }

    public function summary(WizardContext $ctx, array $data): array
    {
        return [['Type', Tenant::KINDS[(string) ($data['kind'] ?? '')] ?? 'Not chosen']];
    }
}
