<?php

declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Tenancy\Tenant;

/** Gives every page the school's published website pages (footer, navigation) and its logo's shape. */
final class ShareSiteLinks implements Middleware
{
    public function __construct(
        private readonly SitePageRepository $pages,
        private readonly View $view,
        private readonly ?\App\Modules\Tenancy\LogoStore $logos = null,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant) {
            $links = [];
            foreach ($this->pages->published($tenant->id) as $page) {
                $links[$page->path()] = $page->title;
            }
            $this->view->share('site_pages', $links);
            // The logo's size lets pages reserve its space, so nothing jumps when it loads.
            $path = $tenant->hasLogo() ? $this->logos?->path((string) $tenant->logoKey) : null;
            $size = $path !== null ? @getimagesize($path) : false;
            if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
                $this->view->share('logo_ratio', $size[0] / $size[1]);
            }
        }

        return $next($request);
    }
}
