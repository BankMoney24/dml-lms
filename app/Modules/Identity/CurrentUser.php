<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Http\HttpException;
use App\Modules\Tenancy\Membership;

/** The signed-in user for this request, together with their membership in the current tenant. */
final class CurrentUser
{
    public function __construct(public readonly User $user, public readonly ?Membership $membership)
    {
    }

    public function can(Permission $permission): bool
    {
        return $this->membership !== null && $this->membership->allows($permission);
    }

    public function authorise(Permission $permission): void
    {
        if (!$this->can($permission)) {
            throw HttpException::forbidden();
        }
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->membership === null || !$this->membership->isActive()) {
            return [];
        }

        return array_map(static fn (Permission $p): string => $p->value, $this->membership->role->permissions());
    }
}
