<?php

declare(strict_types=1);

namespace App\Modules\Wizards;

use App\Core\Http\Request;
use App\Modules\Identity\CurrentUser;
use App\Modules\Tenancy\Tenant;

/** Who is running a wizard, for which institution, on which request. */
final class WizardContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly CurrentUser $user,
        public readonly Request $request,
    ) {
    }
}
