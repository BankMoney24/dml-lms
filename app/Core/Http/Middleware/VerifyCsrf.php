<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;

/** Rejects state-changing cookie-authenticated requests without a valid token. */
final class VerifyCsrf implements Middleware
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$request->isSafeMethod()) {
            $token = $request->input('_token') ?? $request->header('x-csrf-token');
            if (!$this->csrf->isValid($token)) {
                throw new HttpException(419, 'Your session expired before the form was sent. Go back, refresh the page and try again.');
            }
        }

        return $next($request);
    }
}
