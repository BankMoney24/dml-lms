<?php

declare(strict_types=1);

namespace App\Modules\Api\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\RateLimiter;
use App\Modules\Api\ApiKeys;
use App\Modules\Tenancy\Tenant;

/**
 * API v1 requests (ADR 0036): the school comes from the host, the key from
 * "Authorization: Bearer dmlk_…". No cookies or sessions. Errors are JSON.
 * 120 requests a minute per key.
 */
final class ApiAuth implements Middleware
{
    public const PER_MINUTE = 120;

    public function __construct(private readonly ApiKeys $keys, private readonly RateLimiter $limiter)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            return self::error(404, 'not_found', 'This address is not a school.');
        }
        if ($request->method !== 'GET') {
            return self::error(405, 'read_only', 'API v1 only reads. Use GET.');
        }
        $header = (string) ($request->header('authorization') ?? '');
        $token = preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1 ? $m[1] : '';
        $key = $token === '' ? null : $this->keys->authenticate($tenant, $token);
        if ($key === null) {
            return self::error(401, 'unauthorised', 'Send a valid key for this school as "Authorization: Bearer <key>". School administrators make keys under School → Settings → API keys.')
                ->withHeader('WWW-Authenticate', 'Bearer realm="api"');
        }
        if (!$this->limiter->attempt('api-key:' . $key->id, self::PER_MINUTE, 60)) {
            return self::error(429, 'rate_limited', 'Too many requests for this key. Wait a minute and try again.')->withHeader('Retry-After', '60');
        }
        try {
            $response = $next($request->withAttribute('api_key', $key));
        } catch (HttpException $e) {
            $response = self::error($e->status, $e->status === 404 ? 'not_found' : 'error', $e->getMessage() !== '' ? $e->getMessage() : 'Not found.');
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }

    public static function error(int $status, string $code, string $message): Response
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
