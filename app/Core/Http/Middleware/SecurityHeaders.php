<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class SecurityHeaders implements Middleware
{
    public function __construct(private readonly bool $hsts)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        $headers = [
            // Self-hosted assets only; no inline script. Inline styles are not used either.
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "font-src 'self'; connect-src 'self'; "
                // Video lessons: only the privacy-enhanced YouTube player and Vimeo.
                . "frame-src https://www.youtube-nocookie.com https://player.vimeo.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; "
                . "object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($this->hsts) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if ($response->header('Cache-Control') === null) {
            // Pages can contain personal data; do not let shared caches keep them.
            $headers['Cache-Control'] = 'no-store';
        }

        foreach ($headers as $name => $value) {
            if ($name === 'Content-Security-Policy' && $response->header($name) !== null) {
                continue; // a stricter policy set by the controller (downloads) wins
            }
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
