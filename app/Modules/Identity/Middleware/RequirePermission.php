<?php

declare(strict_types=1);

namespace App\Modules\Identity\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;

/**
 * Route-level permission gate. This is a first line only — use cases still
 * check object-level access (the record belongs to this tenant and user).
 */
final class RequirePermission implements Middleware
{
    public function __construct(private readonly Permission $permission)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof CurrentUser || !$auth->can($this->permission)) {
            throw HttpException::forbidden();
        }

        return $next($request);
    }
}
