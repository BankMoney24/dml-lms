<?php

declare(strict_types=1);

namespace App\Modules\Site\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\CourseSchema;
use App\Modules\Identity\Permission;
use App\Modules\Site\SitePage;
use App\Modules\Site\SitePageRepository;
use App\Modules\Site\SitePages;

/** The school's public pages, and where administrators write them (ADR 0019). */
final class SiteController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly SitePages $site,
        private readonly SitePageRepository $pages,
        private readonly ?\App\Modules\Site\FullDrafts $drafts = null,
    ) {
    }

    /** /about, /contact, /privacy …: published pages only. */
    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $slug = ltrim($request->path, '/');
        $page = SitePages::exists($slug) ? $this->pages->find($tenant->id, $slug) : null;
        if ($page === null || !$page->isPublished()) {
            throw HttpException::notFound();
        }

        return $this->renderPublic($request, $page, false);
    }

    /** POST /school/website/{page}/full-draft: replace an unpublished page with a complete draft to read and adjust (ADR 0033). */
    public function fullDraft(Request $request): Response
    {
        $slug = $this->slug($request);
        $tenant = RequestContext::tenant($request);
        $existing = $this->pages->find($tenant->id, $slug);
        $draft = $this->drafts?->draft($slug, $tenant, (new \DateTimeImmutable('now', new \DateTimeZone($tenant->timezone ?: 'UTC')))->format('Y-m-d'));
        if ($draft === null || ($existing !== null && $existing->isPublished())) {
            throw HttpException::notFound();
        }
        $this->site->save($tenant, RequestContext::user($request), $slug, $draft, $request->clientIp);
        $this->session->flash('success', 'A full draft is saved. Read it, change anything that is not right for your school, then publish it.');

        return Response::redirect('/school/website/' . $slug);
    }

    public function index(Request $request): Response
    {
        return $this->settingsPage($request);
    }

    public function edit(Request $request): Response
    {
        return $this->editPage($request, $this->slug($request));
    }

    public function save(Request $request): Response
    {
        $slug = $this->slug($request);
        $input = [
            'title' => $request->input('title', '') ?? '',
            'summary' => $request->input('summary', '') ?? '',
            'body' => $request->input('body', '') ?? '',
        ];
        try {
            $this->site->save(RequestContext::tenant($request), RequestContext::user($request), $slug, $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->editPage($request, $slug, $e->errors, $input, 422);
        }
        $page = $this->pages->find(RequestContext::tenant($request)->id, $slug);
        $this->session->flash('success', $page !== null && $page->isPublished() ? 'Saved. The changes are live.' : 'Draft saved. Publish it when it is ready.');

        return Response::redirect('/school/website/' . $slug);
    }

    public function publish(Request $request): Response
    {
        $slug = $this->slug($request);
        try {
            $this->site->publish(RequestContext::tenant($request), RequestContext::user($request), $slug, $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', $e->errors['page'] ?? 'This page cannot be published yet.');

            return Response::redirect('/school/website/' . $slug);
        }
        $this->session->flash('success', 'Published. The page is on your website and in the footer.');

        return Response::redirect('/school/website/' . $slug);
    }

    public function unpublish(Request $request): Response
    {
        $slug = $this->slug($request);
        $this->site->unpublish(RequestContext::tenant($request), RequestContext::user($request), $slug, $request->clientIp);
        $this->session->flash('success', 'Taken off the website. It is kept here as a draft.');

        return Response::redirect('/school/website/' . $slug);
    }

    /** How a draft will look, for administrators only; never indexed. */
    public function preview(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        RequestContext::user($request)->authorise(Permission::SchoolManage);
        $slug = $this->slug($request);

        return $this->renderPublic($request, $this->site->forEditing($tenant, $slug), true);
    }

    public function saveSettings(Request $request): Response
    {
        $social = [];
        foreach (array_keys(SitePages::SOCIAL) as $key) {
            $social[$key] = $request->input('social_' . $key, '') ?? '';
        }
        $codes = ['google' => $request->input('verify_google', '') ?? '', 'bing' => $request->input('verify_bing', '') ?? ''];
        try {
            $this->site->saveSettings(RequestContext::tenant($request), RequestContext::user($request), $social, $codes, $request->clientIp);
        } catch (ValidationException $e) {
            $old = ['verify_google' => $codes['google'], 'verify_bing' => $codes['bing']];
            foreach ($social as $k => $val) {
                $old['social_' . $k] = $val;
            }

            return $this->settingsPage($request, $e->errors, $old, 422);
        }
        $this->session->flash('success', 'Website settings saved.');

        return Response::redirect('/school/website');
    }

    private function renderPublic(Request $request, SitePage $page, bool $preview): Response
    {
        $tenant = RequestContext::tenant($request);
        $base = RequestContext::baseUrl($request);
        $url = $base . $page->path();
        $type = SitePages::PAGES[$page->slug][1] ?? 'WebPage';

        return Response::html($this->view->render('site/page', [
            'pageTitle' => $page->title,
            'page' => $page,
            'school' => $tenant,
            'preview' => $preview,
            'problems' => $preview ? SitePages::problems($page, $tenant) : [],
            'metaDescription' => $page->summary,
            'indexable' => !$preview,
            'canonicalPath' => $page->path(),
            'seoCrumbs' => [$page->title => $page->path()],
            'ogImage' => '/share/pages/' . $page->slug . '.png?v=' . substr(hash('sha256', $page->updatedAt . $page->title), 0, 10),
            'ogImageAlt' => $page->title . ', ' . $tenant->name,
            'ogImageSize' => [\App\Modules\Platform\Seo\ShareCard::WIDTH, \App\Modules\Platform\Seo\ShareCard::HEIGHT],
            'jsonLd' => $preview ? null : CourseSchema::webPage($type, $page->title, $page->summary, $url, $base, $page->updatedAt !== '' ? str_replace(' ', 'T', $page->updatedAt) . 'Z' : null),
        ]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function editPage(Request $request, string $slug, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        RequestContext::user($request)->authorise(Permission::SchoolManage);
        $page = $this->site->forEditing($tenant, $slug);

        return Response::html($this->view->render('school/website_edit', [
            'pageTitle' => $page->title . ': edit',
            'schoolTab' => 'website',
            'page' => $page,
            'saved' => $page->id !== 0,
            'problems' => SitePages::problems($page, $tenant),
            'hasFullDraft' => $this->drafts !== null && in_array($slug, \App\Modules\Site\FullDrafts::PAGES, true),
            'choices' => \App\Modules\Site\FullDrafts::choices($slug),
            'school' => $tenant,
            'errors' => $errors,
            'old' => $old + ['title' => $page->title, 'summary' => $page->summary, 'body' => $page->body],
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function settingsPage(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        RequestContext::user($request)->authorise(Permission::SchoolManage);
        $defaults = ['verify_google' => $tenant->searchVerification['google'] ?? '', 'verify_bing' => $tenant->searchVerification['bing'] ?? ''];
        foreach (array_keys(SitePages::SOCIAL) as $key) {
            $defaults['social_' . $key] = $tenant->socialLinks[$key] ?? '';
        }

        return Response::html($this->view->render('school/website', [
            'pageTitle' => 'Website',
            'schoolTab' => 'website',
            'school' => $tenant,
            'pages' => $this->pages->all($tenant->id),
            'baseUrl' => RequestContext::baseUrl($request),
            'errors' => $errors,
            'old' => $old + $defaults,
        ]), $status);
    }

    private function slug(Request $request): string
    {
        $slug = $request->routeParam('page');
        if (!SitePages::exists($slug)) {
            throw HttpException::notFound();
        }

        return $slug;
    }
}
