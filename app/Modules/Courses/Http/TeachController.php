<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Assignments\SubmissionRepository;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Learning\ProgressReport;

/** Educator workspace at course level: create, outline, modules, publish, progress. */
final class TeachController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly AssessmentRepository $assessments,
        private readonly CourseAuthoring $authoring,
        private readonly ProgressReport $progress,
        private readonly SubmissionRepository $pendingReviews,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('teach/index', [
            'pageTitle' => 'Teaching',
            'rows' => $this->courses->listAllWithCounts($tenant->id),
            'pendingReviews' => $this->pendingReviews->pendingCount($tenant->id),
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->renderCreate([], ['title' => '', 'summary' => '']);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $old = ['title' => $request->input('title', '') ?? '', 'summary' => $request->input('summary', '') ?? ''];
        try {
            $course = $this->authoring->createCourse($tenant->id, RequestContext::user($request), $old['title'], $old['summary']);
        } catch (ValidationException $e) {
            return $this->renderCreate($e->errors, $old, 422);
        }
        $this->session->flash('success', 'Course created. Add a module, then your first lesson.');

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function edit(Request $request): Response
    {
        return $this->renderEdit($this->course($request));
    }

    public function updateDetails(Request $request): Response
    {
        $course = $this->course($request);
        $old = [];
        foreach (['title', 'summary', 'level', 'duration_hours', 'outcomes', 'prerequisites'] as $key) {
            $old[$key] = $request->input($key, '') ?? '';
        }
        try {
            $this->authoring->updateDetails($course, RequestContext::user($request), $old);
        } catch (ValidationException $e) {
            return $this->renderEdit($course, $e->errors, $old + ['details_open' => '1'], 422);
        }
        $this->session->flash('success', 'Course details saved.');

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function addModule(Request $request): Response
    {
        $course = $this->course($request);
        try {
            $this->authoring->addModule($course, RequestContext::user($request), $request->input('module_title', '') ?? '');
        } catch (ValidationException $e) {
            return $this->renderEdit($course, $e->errors, $this->old($request), 422);
        }
        $this->session->flash('success', 'Module added.');

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function renameModule(Request $request): Response
    {
        $course = $this->course($request);
        try {
            $this->authoring->renameModule($course, RequestContext::user($request), $request->routeInt('module'), $request->input('module_title', '') ?? '');
            $this->session->flash('success', 'Module renamed.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function deleteModule(Request $request): Response
    {
        $course = $this->course($request);
        try {
            $this->authoring->deleteModule($course, RequestContext::user($request), $request->routeInt('module'));
            $this->session->flash('success', 'Module deleted.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function addLesson(Request $request): Response
    {
        $course = $this->course($request);
        try {
            $lessonId = $this->authoring->addLesson($course, RequestContext::user($request), (int) ($request->input('module_id', '0') ?? '0'), [
                'type' => $request->input('type', '') ?? '',
                'title' => $request->input('lesson_title', '') ?? '',
                'body' => $request->input('body', '') ?? '',
                'media_url' => $request->input('media_url', '') ?? '',
                'pass_threshold' => $request->input('pass_threshold', '70') ?? '70',
                'max_attempts' => $request->input('max_attempts', '') ?? '',
            ]);
        } catch (ValidationException $e) {
            return $this->renderEdit($course, $e->errors, $this->old($request), 422);
        }
        $this->session->flash('success', 'Lesson added.');

        return Response::redirect('/teach/courses/' . $course->id . '#lesson-' . $lessonId);
    }

    public function publish(Request $request): Response
    {
        $course = $this->course($request);
        try {
            $this->authoring->publish($course, RequestContext::user($request));
        } catch (ValidationException $e) {
            return $this->renderEdit($course, $e->errors, [], 422);
        }
        $this->session->flash('success', 'Course published. Learners can now find and enrol in it.');

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function unpublish(Request $request): Response
    {
        $course = $this->course($request);
        $this->authoring->unpublish($course, RequestContext::user($request));
        $this->session->flash('success', 'Course unpublished. It is hidden from learners until you publish it again; their progress is kept.');

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function progress(Request $request): Response
    {
        $course = $this->course($request);

        return Response::html($this->view->render('teach/progress', [
            'pageTitle' => 'Progress: ' . $course->title,
            'course' => $course,
            'report' => $this->progress->forCourse($course->tenantId, $course->id),
            'pendingReviews' => $this->pendingReviews->pendingCount($course->tenantId),
        ]));
    }

    private function course(Request $request): Course
    {
        $tenant = RequestContext::tenant($request);

        return $this->courses->findById($tenant->id, $request->routeInt('course')) ?? throw HttpException::notFound();
    }

    /** @return array<string, string> */
    private function old(Request $request): array
    {
        $old = [];
        foreach (['module_title', 'module_id', 'type', 'lesson_title', 'body', 'media_url', 'pass_threshold', 'max_attempts'] as $key) {
            $old[$key] = $request->input($key, '') ?? '';
        }

        return $old;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderCreate(array $errors, array $old, int $status = 200): Response
    {
        return Response::html($this->view->render('teach/create', [
            'pageTitle' => 'New course',
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderEdit(Course $course, array $errors = [], array $old = [], int $status = 200): Response
    {
        $course = $this->courses->findById($course->tenantId, $course->id) ?? $course;
        $outline = $this->lessons->outline($course->tenantId, $course->id);
        $questionCounts = [];
        foreach ($outline as $module) {
            foreach ($module['lessons'] as $lesson) {
                if ($lesson->isQuiz()) {
                    $assessment = $this->assessments->findForLesson($course->tenantId, $lesson->id);
                    $questionCounts[$lesson->id] = $assessment === null ? 0 : $this->assessments->questionCount($course->tenantId, $assessment['id']);
                }
            }
        }

        return Response::html($this->view->render('teach/edit', [
            'pageTitle' => $course->title,
            'course' => $course,
            'outline' => $outline,
            'questionCounts' => $questionCounts,
            'publishProblems' => $course->isPublished() ? [] : $this->authoring->publishProblems($course),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
