<?php

declare(strict_types=1);

namespace App\Modules\Paths\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Identity\Permission;
use App\Modules\Paths\LearningPath;
use App\Modules\Paths\PathRepository;
use App\Modules\Paths\PathService;

/** A learning path's public page, and starting or leaving it (ADR 0023). */
final class PathController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly PathRepository $paths,
        private readonly PathService $service,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::optionalUser($request);
        $path = $this->published($request);
        if ($path === null) {
            return Response::redirect('/login');
        }
        $progress = $this->service->progress($path, $user?->user->id);

        return Response::html($this->view->render('paths/show', [
            'pageTitle' => $path->title,
            'path' => $path,
            'progress' => $progress,
            'following' => $user !== null && $this->paths->follows($tenant->id, $path->id, $user->user->id),
            'canLearn' => $user === null || $user->can(Permission::CourseLearn),
            'signedIn' => $user !== null,
            'signUpOpen' => $tenant->signUpOpen(),
            'indexable' => $tenant->publicCatalogue,
            'metaDescription' => $path->summary !== ''
                ? mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $path->summary)), 0, 155, '…')
                : sprintf('%s: %d courses in order, from %s.', $path->title, $progress['total'], $tenant->name),
            'seoCrumbs' => ['Courses' => '/courses', $path->title => '/paths/' . $path->slug],
        ]));
    }

    public function start(Request $request): Response
    {
        $path = $this->published($request) ?? throw HttpException::notFound();
        $next = $this->service->start($path, RequestContext::user($request));
        if ($next === null) {
            $this->session->flash('success', 'You have finished every course in this path.');

            return Response::redirect('/paths/' . $path->slug);
        }
        $this->session->flash('success', 'You are following "' . $path->title . '". Next: ' . $next->title . '.');

        return Response::redirect('/courses/' . $next->slug);
    }

    public function leave(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $path = $this->published($request) ?? throw HttpException::notFound();
        $this->paths->unfollow($tenant->id, $path->id, RequestContext::user($request)->user->id);
        $this->session->flash('success', 'You no longer follow this path. Your courses and progress stay as they are.');

        return Response::redirect('/paths/' . $path->slug);
    }

    /** Null when a visitor may not see it (members-only course list); 404 when it does not exist or is a draft. */
    private function published(Request $request): ?LearningPath
    {
        $tenant = RequestContext::tenant($request);
        $path = $this->paths->findBySlug($tenant->id, $request->routeParam('slug'));
        $signedIn = RequestContext::optionalUser($request) !== null;
        if ($path === null || !$path->isPublished()) {
            if (!$tenant->publicCatalogue && !$signedIn) {
                return null;
            }
            throw HttpException::notFound();
        }

        return $tenant->publicCatalogue || $signedIn ? $path : null;
    }
}
