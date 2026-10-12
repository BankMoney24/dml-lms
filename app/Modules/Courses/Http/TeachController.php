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
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;

/** Educator workspace: create a course, build its outline, add quiz questions, publish. */
final class TeachController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly AssessmentRepository $assessments,
        private readonly CourseAuthoring $authoring,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('teach/index', [
            'pageTitle' => 'Teaching',
            'rows' => $this->courses->listAllWithCounts($tenant->id),
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

    public function addLesson(Request $request): Response
    {
        $course = $this->course($request);
        try {
            $this->authoring->addLesson($course, RequestContext::user($request), (int) ($request->input('module_id', '0') ?? '0'), [
                'type' => $request->input('type', '') ?? '',
                'title' => $request->input('lesson_title', '') ?? '',
                'body' => $request->input('body', '') ?? '',
                'pass_threshold' => $request->input('pass_threshold', '70') ?? '70',
                'max_attempts' => $request->input('max_attempts', '') ?? '',
            ]);
        } catch (ValidationException $e) {
            return $this->renderEdit($course, $e->errors, $this->old($request), 422);
        }
        $this->session->flash('success', 'Lesson added.');

        return Response::redirect('/teach/courses/' . $course->id);
    }

    public function addQuestion(Request $request): Response
    {
        $course = $this->course($request);
        $lessonId = $request->routeInt('lesson');
        try {
            $this->authoring->addQuestion(
                $course,
                RequestContext::user($request),
                $lessonId,
                $request->input('prompt', '') ?? '',
                array_values($request->inputArray('options')),
                $request->input('correct', '') ?? '',
            );
        } catch (ValidationException $e) {
            return $this->renderEdit($course, $e->errors, $this->old($request) + ['question_lesson' => (string) $lessonId], 422);
        }
        $this->session->flash('success', 'Question added.');

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

    private function course(Request $request): Course
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findById($tenant->id, $request->routeInt('course'));
        if ($course === null) {
            throw HttpException::notFound();
        }

        return $course;
    }

    /** @return array<string, string> */
    private function old(Request $request): array
    {
        $old = [];
        foreach (['module_title', 'module_id', 'type', 'lesson_title', 'body', 'pass_threshold', 'max_attempts', 'prompt', 'correct'] as $key) {
            $old[$key] = $request->input($key, '') ?? '';
        }
        foreach (array_values($request->inputArray('options')) as $i => $label) {
            $old['option_' . $i] = $label;
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
        $outline = $this->lessons->outline($course->tenantId, $course->id);
        $questionCounts = [];
        foreach ($outline as $module) {
            foreach ($module['lessons'] as $lesson) {
                if ($lesson->isQuiz()) {
                    $assessment = $this->assessments->findForLesson($course->tenantId, $lesson->id);
                    $questionCounts[$lesson->id] = $assessment === null
                        ? 0
                        : $this->assessments->questionCount($course->tenantId, $assessment['id']);
                }
            }
        }

        // Re-read the course so status reflects any publish in this request.
        $course = $this->courses->findById($course->tenantId, $course->id) ?? $course;

        return Response::html($this->view->render('teach/edit', [
            'pageTitle' => 'Edit ' . $course->title,
            'course' => $course,
            'outline' => $outline,
            'questionCounts' => $questionCounts,
            'publishProblems' => $course->isPublished() ? [] : $this->authoring->publishProblems($course),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
