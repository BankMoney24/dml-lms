<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Modules\Identity\CurrentUser;
use App\Modules\Tenancy\Tenant;

/** Typed accessors for per-request context set by middleware. */
final class RequestContext
{
    public static function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw HttpException::notFound();
        }

        return $tenant;
    }

    public static function user(Request $request): CurrentUser
    {
        $user = $request->attribute('auth');
        if (!$user instanceof CurrentUser) {
            throw HttpException::forbidden();
        }

        return $user;
    }

    public static function optionalUser(Request $request): ?CurrentUser
    {
        $user = $request->attribute('auth');

        return $user instanceof CurrentUser ? $user : null;
    }
}
