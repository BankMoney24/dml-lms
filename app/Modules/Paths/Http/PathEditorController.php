<?php

declare(strict_types=1);

namespace App\Modules\Paths\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Paths\LearningPath;
use App\Modules\Paths\PathRepository;
use App\Modules\Paths\PathService;

/** Teach → Paths: build and order learning paths (ADR 0023). */
final class PathEditorController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly PathRepository $paths,
        private readonly PathService $service,
        private readonly CourseRepository $courses,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $old = ['title' => $request->input('title', '') ?? '', 'summary' => $request->input('summary', '') ?? ''];
        try {
            $path = $this->service->create($tenant->id, RequestContext::user($request), $old['title'], $old['summary']);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors, $old, 422);
        }
        $this->session->flash('success', 'Path created. Now add its courses in order.');

        return Response::redirect('/teach/paths/' . $path->id);
    }

    public function edit(Request $request): Response
    {
        return $this->renderEdit($request, $this->path($request));
    }

    public function update(Request $request): Response
    {
        $path = $this->path($request);
        $old = ['title' => $request->input('title', '') ?? '', 'summary' => $request->input('summary', '') ?? '', 'level' => $request->input('level', '') ?? ''];
        try {
            $this->service->update($path, RequestContext::user($request), $old['title'], $old['summary'], $old['level']);
        } catch (ValidationException $e) {
            return $this->renderEdit($request, $path, $e->errors, $old, 422);
        }
        $this->session->flash('success', 'Saved.');

        return Response::redirect('/teach/paths/' . $path->id);
    }

    public function addCourse(Request $request): Response
    {
        $path = $this->path($request);
        try {
            $this->service->addCourse($path, RequestContext::user($request), (int) ($request->input('course_id', '0') ?? '0'));
        } catch (ValidationException $e) {
            return $this->renderEdit($request, $path, $e->errors, [], 422);
        }

        return Response::redirect('/teach/paths/' . $path->id . '#steps');
    }

    public function removeCourse(Request $request): Response
    {
        $path = $this->path($request);
        try {
            $this->service->removeCourse($path, RequestContext::user($request), $request->routeInt('course'));
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/teach/paths/' . $path->id . '#steps');
    }

    public function move(Request $request): Response
    {
        $path = $this->path($request);
        $this->service->move($path, RequestContext::user($request), $request->routeInt('course'), $request->input('direction') === 'up' ? -1 : 1);

        return Response::redirect('/teach/paths/' . $path->id . '#steps');
    }

    public function publish(Request $request): Response
    {
        $path = $this->path($request);
        $publish = $request->input('publish') === '1';
        try {
            $this->service->publish($path, RequestContext::user($request), $publish);
            $this->session->flash('success', $publish ? 'Published. Learners find it on the course list.' : 'Unpublished. Followers keep their courses and progress.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/teach/paths/' . $path->id);
    }

    public function delete(Request $request): Response
    {
        $path = $this->path($request);
        if ($request->input('confirm') !== $path->slug) {
            $this->session->flash('error', 'Type the path\'s address to confirm.');

            return Response::redirect('/teach/paths/' . $path->id . '#delete');
        }
        $this->service->delete($path, RequestContext::user($request));
        $this->session->flash('success', 'Path deleted. Its courses are unchanged.');

        return Response::redirect('/teach/paths');
    }

    private function path(Request $request): LearningPath
    {
        return $this->paths->find(RequestContext::tenant($request)->id, $request->routeInt('path')) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderIndex(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('teach/paths', [
            'pageTitle' => 'Learning paths',
            'paths' => $this->paths->all($tenant->id),
            'errors' => $errors,
            'old' => $old + ['title' => '', 'summary' => ''],
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderEdit(Request $request, LearningPath $path, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $progress = $this->service->progress($path, null, true);
        $inPath = array_map(static fn (array $s): int => $s['course']->id, $progress['steps']);
        $available = array_values(array_filter(
            array_map(static fn (array $r) => $r['course'], $this->courses->listAllWithCounts($tenant->id)),
            static fn ($c): bool => !in_array($c->id, $inPath, true),
        ));

        return Response::html($this->view->render('teach/path_edit', [
            'pageTitle' => $path->title,
            'path' => $path,
            'steps' => $progress['steps'],
            'available' => $available,
            'problems' => $path->isPublished() ? [] : $this->service->publishProblems($path),
            'followers' => $this->paths->followerCount($tenant->id, $path->id),
            'errors' => $errors,
            'old' => $old + ['title' => $path->title, 'summary' => $path->summary, 'level' => (string) $path->level],
        ]), $status);
    }
}
