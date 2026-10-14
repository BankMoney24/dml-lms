<?php

declare(strict_types=1);

namespace App\Modules\Platform\Seo;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\CourseRepository;
use App\Modules\Site\SitePageRepository;
use App\Modules\Site\SitePages;
use App\Modules\Tenancy\LogoStore;
use App\Modules\Tenancy\Tenant;

/**
 * /share/…png: the images social networks show when someone shares a link.
 * Public facts only (the school's name, logo and tagline, published course
 * and page titles), no cookies, cached for a day.
 */
final class ShareController
{
    public function __construct(
        private readonly ShareCard $cards,
        private readonly CourseRepository $courses,
        private readonly SitePageRepository $pages,
        private readonly LogoStore $logos,
        private readonly string $fallback,
    ) {
    }

    public function site(Request $request): Response
    {
        $tenant = $this->tenant($request);

        return $this->send($this->cards->render($tenant, $this->logo($tenant), 'site', $tenant->name, $tenant->kindLabel() ?? 'Learning online', 'site-' . $tenant->slug, 'site'));
    }

    public function course(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished() || !$tenant->publicCatalogue) {
            throw HttpException::notFound();
        }

        return $this->send($this->cards->render($tenant, $this->logo($tenant), 'course-' . $course->id, $course->title, 'Course', $course->slug, $course->updatedAt));
    }

    public function page(Request $request): Response
    {
        $tenant = $this->tenant($request);
        $slug = $request->routeParam('page');
        $page = SitePages::exists($slug) ? $this->pages->find($tenant->id, $slug) : null;
        if ($page === null || !$page->isPublished()) {
            throw HttpException::notFound();
        }

        return $this->send($this->cards->render($tenant, $this->logo($tenant), 'page-' . $slug, $page->title, $tenant->name, 'page-' . $slug, $page->updatedAt));
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw HttpException::notFound();
        }

        return $tenant;
    }

    private function logo(Tenant $tenant): ?string
    {
        return $tenant->hasLogo() ? $this->logos->path((string) $tenant->logoKey) : null;
    }

    private function send(?string $file): Response
    {
        $path = $file ?? $this->fallback;
        $type = $file === null ? 'image/jpeg' : 'image/png';

        return Response::file($path, $type, 'share', true)
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
