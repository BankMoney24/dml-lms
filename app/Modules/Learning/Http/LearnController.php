<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Assessments\AttemptRepository;
use App\Modules\Assessments\QuizQuestion;
use App\Modules\Assignments\AssignmentService;
use App\Modules\Assignments\SubmissionRepository;
use App\Core\Validation\ValidationException;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;
use App\Modules\Learning\LearningService;
use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\LabSessionRepository;

final class LearnController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly LearningService $learning,
        private readonly AssessmentRepository $assessments,
        private readonly AttemptRepository $attempts,
        private readonly AssignmentService $assignments,
        private readonly SubmissionRepository $submissions,
        private readonly FileRepository $files,
        private readonly FileService $fileService,
        private readonly LabCatalogue $labs,
        private readonly LabSessionRepository $labSessions,
        private readonly ?\App\Modules\Study\NoteRepository $notes = null,
        private readonly ?\App\Modules\Discussions\QuestionRepository $questions = null,
    ) {
    }

    public function enrol(Request $request): Response
    {
        $course = $this->publishedCourse($request);
        if ($this->learning->needsPayment($course, RequestContext::user($request))) {
            $this->session->flash('error', 'This course is paid. Pay for it below to start.');

            return Response::redirect('/courses/' . $course->slug);
        }
        $this->learning->enrol($course, RequestContext::user($request));
        $first = $this->lessons->orderedLessons($course->tenantId, $course->id)[0] ?? null;
        $this->session->flash('success', 'You are enrolled. Start with the first lesson.');

        return Response::redirect($first === null
            ? '/courses/' . $course->slug
            : '/courses/' . $course->slug . '/lessons/' . $first->id);
    }

    public function lesson(Request $request): Response
    {
        $course = $this->publishedCourse($request);
        $lesson = $this->lessonOf($course, $request);
        $user = RequestContext::user($request);
        if (!$user->can(\App\Modules\Identity\Permission::CourseLearn)) {
            // Teachers follow lesson links too (from notifications, reports, questions): show them the preview.
            if ($user->can(\App\Modules\Identity\Permission::CourseManage)) {
                return Response::redirect('/teach/courses/' . $course->id . '/lessons/' . $lesson->id . '/preview');
            }
            throw HttpException::forbidden();
        }
        if ($this->learning->findEnrolment($course, $user) === null) {
            // Not enrolled: send people where they can see this lesson instead of a dead end (ADR 0021).
            if ($course->lessonIsReadable($lesson)) {
                return Response::redirect('/courses/' . $course->slug . '/preview/' . $lesson->id);
            }
            if ($user->can(\App\Modules\Identity\Permission::CourseManage)) {
                return Response::redirect('/teach/courses/' . $course->id . '/lessons/' . $lesson->id . '/preview');
            }
            $this->session->flash('error', 'Join the course to open "' . $lesson->title . '".');

            return Response::redirect('/courses/' . $course->slug . '#start');
        }

        return $this->renderLesson($request, $course, $lesson);
    }

    public function submitAssignment(Request $request): Response
    {
        $course = $this->publishedCourse($request);
        $enrolment = $this->learning->requireEnrolment($course, RequestContext::user($request));
        $lesson = $this->lessonOf($course, $request);
        $answer = $request->input('answer', '') ?? '';
        $link = $request->input('link', '') ?? '';
        try {
            $this->assignments->submit($enrolment, $lesson, $answer, $link, $request->clientIp, $request->files('attachments'));
        } catch (ValidationException $e) {
            return $this->renderLesson($request, $course, $lesson, $e->errors, ['answer' => $answer, 'link' => $link], 422);
        }
        $this->session->flash('success', 'Submitted. Your teacher will review it; you will see their feedback here.');

        return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id . '#submission');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderLesson(Request $request, Course $course, Lesson $lesson, array $errors = [], array $old = [], int $status = 200): Response
    {
        $user = RequestContext::user($request);
        $enrolment = $this->learning->requireEnrolment($course, $user);
        $this->learning->markOpened($enrolment, $lesson);

        $ordered = $this->lessons->orderedLessons($course->tenantId, $course->id);
        $index = array_search($lesson->id, array_map(static fn (Lesson $l): int => $l->id, $ordered), true);
        $index = $index === false ? 0 : $index;

        $quiz = null;
        if ($lesson->isQuiz()) {
            $assessment = $this->assessments->findForLesson($course->tenantId, $lesson->id);
            if ($assessment !== null) {
                $used = $this->attempts->countFor($course->tenantId, $assessment['id'], $user->user->id);
                $quiz = [
                    'pass_threshold' => $assessment['pass_threshold'],
                    'attempts_left' => $assessment['max_attempts'] === null ? null : max(0, $assessment['max_attempts'] - $used),
                    'best' => $this->attempts->bestScore($course->tenantId, $assessment['id'], $user->user->id),
                    'questions' => array_map(
                        static fn (QuizQuestion $q): array => $q->forLearner(),
                        $this->assessments->questions($course->tenantId, $assessment['id']),
                    ),
                    'idempotency_key' => bin2hex(random_bytes(16)),
                ];
            }
        }

        $lab = null;
        if ($lesson->isLab()) {
            $history = $this->labSessions->history($course->tenantId, $enrolment->id, $lesson->id);
            $submitted = array_values(array_filter($history, static fn ($s): bool => $s->score !== null));
            $lab = [
                'definition' => $this->labs->find($lesson->labKey)?->definition(),
                'open' => $this->labSessions->openFor($course->tenantId, $enrolment->id, $lesson->id),
                'history' => $history,
                'best' => $submitted === [] ? null : max(array_map(static fn ($s): int => (int) $s->score, $submitted)),
            ];
        }

        $completedIds = $this->learning->completedLessonIds($enrolment);
        $submissions = $lesson->isAssignment() ? $this->submissions->history($course->tenantId, $enrolment->id, $lesson->id) : [];

        return Response::html($this->view->render('learn/lesson', [
            'pageTitle' => $lesson->title,
            'course' => $course,
            'lesson' => $lesson,
            'position' => $index + 1,
            'total' => count($ordered),
            'previous' => $ordered[$index - 1] ?? null,
            'next' => $ordered[$index + 1] ?? null,
            'completed' => in_array($lesson->id, $completedIds, true),
            'outline' => $this->lessons->outline($course->tenantId, $course->id),
            'completedIds' => $completedIds,
            'quiz' => $quiz,
            'lab' => $lab,
            'submissions' => $submissions,
            'submissionFiles' => $this->files->forSubmissions($course->tenantId, array_map(static fn ($s): int => $s->id, $submissions)),
            'lessonFiles' => $this->files->forLesson($course->tenantId, $lesson->id),
            'policy' => $this->fileService->policy(),
            'note' => $this->notes?->find($course->tenantId, $user->user->id, $lesson->id),
            'questions' => $this->questions?->forLesson($course->tenantId, $lesson->id) ?? [],
            'qaEnabled' => $this->questions !== null,
            'qaDrafts' => \App\Modules\Discussions\Http\DiscussionController::draftsFrom($this->session),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    public function complete(Request $request): Response
    {
        $course = $this->publishedCourse($request);
        $enrolment = $this->learning->requireEnrolment($course, RequestContext::user($request));
        $lesson = $this->lessonOf($course, $request);
        $courseDone = $this->learning->completeTextLesson($enrolment, $lesson);

        $next = null;
        $ordered = $this->lessons->orderedLessons($course->tenantId, $course->id);
        foreach ($ordered as $i => $l) {
            if ($l->id === $lesson->id) {
                $next = $ordered[$i + 1] ?? null;
            }
        }

        if ($courseDone) {
            $this->session->flash('success', 'Course complete. Well done!');

            return Response::redirect('/courses/' . $course->slug);
        }
        $this->session->flash('success', 'Lesson marked complete.');

        return Response::redirect($next === null
            ? '/courses/' . $course->slug
            : '/courses/' . $course->slug . '/lessons/' . $next->id);
    }

    private function publishedCourse(Request $request): Course
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }

        return $course;
    }

    private function lessonOf(Course $course, Request $request): Lesson
    {
        $lesson = $this->lessons->findInCourse($course->tenantId, $course->id, $request->routeInt('lesson'));
        if ($lesson === null) {
            throw HttpException::notFound();
        }

        return $lesson;
    }
}
