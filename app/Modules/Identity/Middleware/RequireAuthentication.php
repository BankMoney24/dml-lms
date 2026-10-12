<?php

declare(strict_types=1);

namespace App\Modules\Identity\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Modules\Identity\CurrentUser;

final class RequireAuthentication implements Middleware
{
    public const INTENDED_KEY = 'auth.intended';

    public function __construct(private readonly Session $session)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$request->attribute('auth') instanceof CurrentUser) {
            if ($request->method === 'GET') {
                $this->session->set(self::INTENDED_KEY, $request->path);
            }

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
