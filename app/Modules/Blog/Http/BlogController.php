<?php

declare(strict_types=1);

namespace App\Modules\Blog\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\Pager;
use App\Core\View\View;
use App\Modules\Blog\BlogPost;
use App\Modules\Blog\BlogRepository;

/** The public blog (ADR 0034): /blog, /blog/{slug}, and an RSS feed at /blog/feed.xml. */
final class BlogController
{
    private const PER_PAGE = 12;

    public function __construct(private readonly View $view, private readonly BlogRepository $posts)
    {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $category = $request->query('category');
        $category = $category !== null && isset(BlogPost::CATEGORIES[$category]) ? $category : null;
        $first = $this->posts->published($tenant->id, $category, 0, 1);
        if ($first['total'] === 0 && $category === null) {
            throw HttpException::notFound();
        }
        $pager = new Pager(Pager::pageFrom($request->query('page')), self::PER_PAGE, $first['total'], '/blog', ['category' => $category ?? '']);
        $page = $this->posts->published($tenant->id, $category, $pager->offset(), self::PER_PAGE);
        $base = RequestContext::baseUrl($request);
        $title = $category === null ? 'Blog' : BlogPost::CATEGORIES[$category][1];

        return Response::html($this->view->render('blog/index', [
            'pageTitle' => $title,
            'posts' => $page['posts'],
            'pager' => $pager,
            'category' => $category,
            'counts' => $this->posts->categoryCounts($tenant->id),
            'school' => $tenant,
            'metaDescription' => 'News, events, stories and guides from ' . $tenant->name . '.',
            'indexable' => true,
            'canonicalPath' => $pager->url($pager->page),
            'seoCrumbs' => ['Blog' => '/blog'],
            'jsonLd' => ['@type' => 'Blog', '@id' => $base . '/blog#blog', 'url' => $base . '/blog', 'name' => $tenant->name . ' blog', 'publisher' => ['@id' => $base . '/#organization']],
        ]));
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $post = $this->posts->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($post === null || !$post->isPublished()) {
            throw HttpException::notFound();
        }

        return $this->render($request, $post, false);
    }

    /** Shared with the school's preview of a draft. */
    public function render(Request $request, BlogPost $post, bool $preview): Response
    {
        $tenant = RequestContext::tenant($request);
        $base = RequestContext::baseUrl($request);
        $iso = static fn (?string $at): ?string => $at === null || $at === '' ? null : str_replace(' ', 'T', $at) . 'Z';
        $more = array_values(array_filter(
            $this->posts->published($tenant->id, null, 0, 4)['posts'],
            static fn (BlogPost $p): bool => $p->id !== $post->id,
        ));

        return Response::html($this->view->render('blog/show', [
            'pageTitle' => $post->title,
            'post' => $post,
            'preview' => $preview,
            'more' => array_slice($more, 0, 3),
            'metaDescription' => $post->summary,
            'indexable' => !$preview,
            'canonicalPath' => $post->path(),
            'ogType' => 'article',
            'seoCrumbs' => ['Blog' => '/blog', $post->title => $post->path()],
            'ogImage' => '/share/posts/' . rawurlencode($post->slug) . '.png?v=' . substr(hash('sha256', $post->updatedAt . $post->title), 0, 10),
            'ogImageAlt' => $post->title . ', ' . $tenant->name,
            'ogImageSize' => [\App\Modules\Platform\Seo\ShareCard::WIDTH, \App\Modules\Platform\Seo\ShareCard::HEIGHT],
            'jsonLd' => $preview ? null : array_filter([
                '@type' => 'BlogPosting',
                '@id' => $base . $post->path() . '#post',
                'url' => $base . $post->path(),
                'headline' => $post->title,
                'description' => $post->summary,
                'datePublished' => $iso($post->publishedAt),
                'dateModified' => $iso($post->updatedAt),
                'author' => ['@type' => 'Person', 'name' => $post->authorName],
                'publisher' => ['@id' => $base . '/#organization'],
                'isPartOf' => ['@id' => $base . '/blog#blog'],
                'articleSection' => $post->categoryLabel(),
            ], static fn ($v): bool => $v !== null),
        ]));
    }

    /** RSS 2.0, the latest 20 posts. */
    public function feed(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $base = RequestContext::baseUrl($request);
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $items = '';
        foreach ($this->posts->published($tenant->id, null, 0, 20)['posts'] as $post) {
            $date = gmdate(DATE_RSS, (int) strtotime(($post->publishedAt ?? $post->updatedAt) . ' UTC'));
            $items .= '<item><title>' . $x($post->title) . '</title><link>' . $x($base . $post->path()) . '</link><guid isPermaLink="true">' . $x($base . $post->path())
                . '</guid><pubDate>' . $date . '</pubDate><category>' . $x($post->categoryLabel()) . '</category><description>' . $x($post->summary) . "</description></item>\n";
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
            . '<title>' . $x($tenant->name . ' blog') . '</title><link>' . $x($base . '/blog') . '</link>'
            . '<description>' . $x('News, events, stories and guides from ' . $tenant->name . '.') . '</description><language>en</language>'
            . '<atom:link href="' . $x($base . '/blog/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n" . $items . '</channel></rss>';

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8', 'Cache-Control' => 'public, max-age=900']);
    }
}
