<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;

/**
 * Shares the canonical address with every page, and (when switched on with
 * CANONICAL_REDIRECT) sends page views that arrive on an alias host or on
 * plain HTTP to the same path on the canonical address with a permanent
 * redirect, so search engines see one copy of each page. Only GET and HEAD
 * move: a form posted to an alias still works.
 */
final class CanonicalHost implements Middleware
{
    public function __construct(private readonly CanonicalUrl $canonical, private readonly View $view, private readonly bool $redirect)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            return $next($request);
        }
        $base = $this->canonical->base($tenant, $request);
        $this->view->share('base_url', $base);
        $request = $request->withAttribute('canonical_base', $base);

        if ($this->redirect && in_array($request->method, ['GET', 'HEAD'], true) && $this->canonical->isAlias($tenant, $request)) {
            $query = $request->queryString();

            return Response::toCanonical($base . $request->path . ($query !== '' ? '?' . $query : ''));
        }

        return $next($request);
    }
}
