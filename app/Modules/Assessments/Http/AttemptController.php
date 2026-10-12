<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Assessments\AttemptRepository;
use App\Modules\Assessments\AttemptService;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Learning\LearningService;

final class AttemptController
{
    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly LearningService $learning,
        private readonly EnrolmentRepository $enrolments,
        private readonly AttemptService $attemptService,
        private readonly AttemptRepository $attempts,
        private readonly AssessmentRepository $assessments,
    ) {
    }

    public function submit(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null) {
            throw HttpException::notFound();
        }
        $enrolment = $this->learning->requireEnrolment($course, $user);
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson'));
        if ($lesson === null) {
            throw HttpException::notFound();
        }

        $attemptId = $this->attemptService->submit(
            $enrolment,
            $lesson,
            $request->inputArray('answers'),
            $request->input('idempotency_key', '') ?? '',
        );

        return Response::redirect(sprintf('/courses/%s/lessons/%d/attempts/%d', $course->slug, $lesson->id, $attemptId));
    }

    public function result(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        $lesson = $course === null ? null : $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson'));
        // Owner-only lookup: another learner's attempt id is simply "not found".
        $attempt = $this->attempts->findForUser($tenant->id, $user->user->id, $request->routeInt('attempt'));
        $assessment = $lesson === null ? null : $this->assessments->findForLesson($tenant->id, $lesson->id);
        if (
            $course === null || $lesson === null || $attempt === null || $assessment === null
            || $assessment['id'] !== $attempt['assessment_id']
        ) {
            throw HttpException::notFound();
        }
        $enrolment = $this->enrolments->find($tenant->id, $course->id, $user->user->id);

        $next = null;
        $ordered = $this->lessons->orderedLessons($tenant->id, $course->id);
        foreach ($ordered as $i => $l) {
            if ($l->id === $lesson->id) {
                $next = $ordered[$i + 1] ?? null;
            }
        }

        // Answers and explanations are revealed once the learner has passed, or has
        // no attempts left; before that they only see which questions were wrong.
        $used = $this->attempts->countFor($tenant->id, $assessment['id'], $user->user->id);
        $outOfAttempts = $assessment['max_attempts'] !== null && $used >= $assessment['max_attempts'];
        $reveal = $attempt['passed'] || $outOfAttempts || $this->attempts->hasPassed($tenant->id, $assessment['id'], $user->user->id);
        $review = [];
        foreach ($this->assessments->questions($tenant->id, $assessment['id']) as $question) {
            $chosen = $attempt['answers'][$question->id] ?? null;
            $review[] = [
                'prompt' => $question->prompt,
                'chosen' => $question->optionLabel($chosen),
                'correct' => $chosen !== null && $chosen === $question->correctOptionId(),
                'answer' => $reveal ? $question->optionLabel($question->correctOptionId()) : null,
                'explanation' => $reveal ? $question->explanation : '',
            ];
        }

        return Response::html($this->view->render('learn/attempt_result', [
            'pageTitle' => 'Quiz result',
            'course' => $course,
            'lesson' => $lesson,
            'attempt' => $attempt,
            'review' => $review,
            'revealed' => $reveal,
            'next' => $next,
            'courseCompleted' => $enrolment !== null && $enrolment->isCompleted(),
        ]));
    }
}
