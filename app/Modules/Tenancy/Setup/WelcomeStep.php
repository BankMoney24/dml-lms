<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Setup;

use App\Modules\Wizards\WizardStep;

final class WelcomeStep extends WizardStep
{
    public function key(): string
    {
        return 'welcome';
    }

    public function title(): string
    {
        return 'Welcome';
    }

    public function view(): string
    {
        return 'school/welcome';
    }

    public function inReview(): bool
    {
        return false;
    }
}
