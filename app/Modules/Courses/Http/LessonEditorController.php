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
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;

/** Educator workspace at lesson level: edit, reorder, delete, questions, preview. */
final class LessonEditorController
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

    public function edit(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);

        return $this->render($course, $lesson);
    }

    public function update(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $input = [
            'title' => $request->input('lesson_title', '') ?? '',
            'body' => $request->input('body', '') ?? '',
            'pass_threshold' => $request->input('pass_threshold', '70') ?? '70',
            'max_attempts' => $request->input('max_attempts', '') ?? '',
        ];
        try {
            $this->authoring->updateLesson($course, RequestContext::user($request), $lesson->id, $input);
        } catch (ValidationException $e) {
            return $this->render($course, $lesson, $e->errors, [
                'lesson_title' => $input['title'],
                'body' => $input['body'],
                'pass_threshold' => $input['pass_threshold'],
                'max_attempts' => $input['max_attempts'],
            ], 422);
        }
        $this->session->flash('success', 'Lesson saved.');

        return Response::redirect($this->editUrl($course, $lesson));
    }

    public function move(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $this->authoring->moveLesson($course, RequestContext::user($request), $lesson->id, $request->input('direction', '') ?? '');

        return Response::redirect('/teach/courses/' . $course->id . '#lesson-' . $lesson->id);
    }

    public function delete(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        try {
            $this->authoring->deleteLesson($course, RequestContext::user($request), $lesson->id);
            $this->session->flash('success', sprintf('Lesson “%s” deleted.', $lesson->title));

            return Response::redirect('/teach/courses/' . $course->id);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect($this->editUrl($course, $lesson));
        }
    }

    public function addQuestion(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $options = array_values($request->inputArray('options'));
        try {
            $this->authoring->addQuestion(
                $course,
                RequestContext::user($request),
                $lesson->id,
                $request->input('prompt', '') ?? '',
                $options,
                $request->input('correct', '') ?? '',
            );
        } catch (ValidationException $e) {
            $old = ['prompt' => $request->input('prompt', '') ?? '', 'correct' => $request->input('correct', '') ?? ''];
            foreach ($options as $i => $label) {
                $old['option_' . $i] = $label;
            }

            return $this->render($course, $lesson, $e->errors, $old, 422);
        }
        $this->session->flash('success', 'Question added.');

        return Response::redirect($this->editUrl($course, $lesson) . '#questions');
    }

    public function deleteQuestion(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        try {
            $this->authoring->deleteQuestion($course, RequestContext::user($request), $lesson->id, $request->routeInt('question'));
            $this->session->flash('success', 'Question removed.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect($this->editUrl($course, $lesson) . '#questions');
    }

    /** Shows the lesson as learners see it, with correct answers marked. Never records progress. */
    public function preview(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $assessment = $lesson->isQuiz() ? $this->assessments->findForLesson($course->tenantId, $lesson->id) : null;

        return Response::html($this->view->render('teach/preview', [
            'pageTitle' => 'Preview: ' . $lesson->title,
            'course' => $course,
            'lesson' => $lesson,
            'assessment' => $assessment,
            'questions' => $assessment === null ? [] : $this->assessments->questions($course->tenantId, $assessment['id']),
        ]));
    }

    /** @return array{0: Course, 1: Lesson} */
    private function load(Request $request): array
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findById($tenant->id, $request->routeInt('course')) ?? throw HttpException::notFound();
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson')) ?? throw HttpException::notFound();

        return [$course, $lesson];
    }

    private function editUrl(Course $course, Lesson $lesson): string
    {
        return sprintf('/teach/courses/%d/lessons/%d', $course->id, $lesson->id);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Course $course, Lesson $lesson, array $errors = [], array $old = [], int $status = 200): Response
    {
        $assessment = $lesson->isQuiz() ? $this->assessments->findForLesson($course->tenantId, $lesson->id) : null;
        $attempts = $assessment === null ? 0 : $this->assessments->attemptCount($course->tenantId, $assessment['id']);

        return Response::html($this->view->render('teach/lesson', [
            'pageTitle' => 'Edit ' . $lesson->title,
            'course' => $course,
            'lesson' => $lesson,
            'assessment' => $assessment,
            'questions' => $assessment === null ? [] : $this->assessments->questions($course->tenantId, $assessment['id']),
            'attempts' => $attempts,
            'inUse' => $attempts > 0 || $this->lessons->hasLearnerActivity($course->tenantId, $lesson->id),
            'errors' => $errors,
            'old' => $old + [
                'lesson_title' => $lesson->title,
                'body' => $lesson->body,
                'pass_threshold' => (string) ($assessment['pass_threshold'] ?? 70),
                'max_attempts' => $assessment === null || $assessment['max_attempts'] === null ? '' : (string) $assessment['max_attempts'],
            ],
        ]), $status);
    }
}
