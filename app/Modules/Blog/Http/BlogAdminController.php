<?php

declare(strict_types=1);

namespace App\Modules\Blog\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Blog\Blog;
use App\Modules\Blog\BlogPost;
use App\Modules\Blog\BlogRepository;

/** School → Website → Blog (ADR 0034). */
final class BlogAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Blog $blog,
        private readonly BlogRepository $posts,
        private readonly BlogController $public,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('school/blog', [
            'pageTitle' => 'Blog',
            'posts' => $this->posts->all($tenant->id),
            'starterChoices' => \App\Modules\Blog\StarterPosts::choices(),
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->editor($request, null);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request);
        try {
            $post = $this->blog->save(RequestContext::tenant($request), RequestContext::user($request), null, $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->editor($request, null, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Draft saved. Preview it, then publish when it is ready.');

        return Response::redirect('/school/blog/' . $post->id);
    }

    public function starters(Request $request): Response
    {
        $added = $this->blog->addStarters(RequestContext::tenant($request), RequestContext::user($request), $request->clientIp);
        $this->session->flash('success', $added === 0
            ? 'The starter drafts are already here.'
            : 'Added ' . $added . ' starter ' . ($added === 1 ? 'draft' : 'drafts') . '. Read each one, change what you like, then publish.');

        return Response::redirect('/school/blog');
    }

    public function edit(Request $request): Response
    {
        return $this->editor($request, $this->post($request));
    }

    public function update(Request $request): Response
    {
        $post = $this->post($request);
        $input = $this->input($request);
        try {
            $this->blog->save(RequestContext::tenant($request), RequestContext::user($request), $post, $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->editor($request, $post, $e->errors, $input, 422);
        }
        $this->session->flash('success', $post->isPublished() ? 'Saved. The changes are live.' : 'Draft saved.');

        return Response::redirect('/school/blog/' . $post->id);
    }

    public function preview(Request $request): Response
    {
        return $this->public->render($request, $this->post($request), true);
    }

    public function publish(Request $request): Response
    {
        $post = $this->post($request);
        try {
            $this->blog->publish(RequestContext::tenant($request), RequestContext::user($request), $post, $request->clientIp);
            $this->session->flash('success', 'Published at ' . $post->path() . '.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/school/blog/' . $post->id);
    }

    public function unpublish(Request $request): Response
    {
        $post = $this->post($request);
        $this->blog->unpublish(RequestContext::tenant($request), RequestContext::user($request), $post, $request->clientIp);
        $this->session->flash('success', 'Taken off the blog. It is a draft again.');

        return Response::redirect('/school/blog/' . $post->id);
    }

    public function delete(Request $request): Response
    {
        $post = $this->post($request);
        try {
            $this->blog->delete(RequestContext::tenant($request), RequestContext::user($request), $post, $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/school/blog/' . $post->id);
        }
        $this->session->flash('success', 'Deleted “' . $post->title . '”.');

        return Response::redirect('/school/blog');
    }

    /** @return array{title: string, summary: string, body: string, category: string, slug: string} */
    private function input(Request $request): array
    {
        $out = [];
        foreach (['title', 'summary', 'body', 'category', 'slug'] as $key) {
            $out[$key] = (string) ($request->input($key, '') ?? '');
        }

        return $out;
    }

    private function post(Request $request): BlogPost
    {
        return $this->posts->find(RequestContext::tenant($request)->id, $request->routeInt('post')) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function editor(Request $request, ?BlogPost $post, array $errors = [], array $old = [], int $status = 200): Response
    {
        return Response::html($this->view->render('school/blog_edit', [
            'pageTitle' => $post === null ? 'New post' : $post->title . ': edit',
            'post' => $post,
            'problems' => $post === null ? [] : Blog::problems($post),
            'errors' => $errors,
            'old' => $old + [
                'title' => $post->title ?? '', 'summary' => $post->summary ?? '', 'body' => $post->body ?? '',
                'category' => $post->category ?? 'news', 'slug' => $post->slug ?? '',
            ],
        ]), $status);
    }
}
