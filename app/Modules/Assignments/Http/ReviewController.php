<?php

declare(strict_types=1);

namespace App\Modules\Assignments\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Assignments\AssignmentService;
use App\Modules\Assignments\Submission;
use App\Modules\Assignments\SubmissionRepository;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\UserRepository;

/** Teachers' queue of assignment submissions waiting for review. */
final class ReviewController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly SubmissionRepository $submissions,
        private readonly AssignmentService $assignments,
        private readonly LessonRepository $lessons,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('teach/reviews', [
            'pageTitle' => 'Reviews',
            'queue' => $this->submissions->queue($tenant->id),
        ]));
    }

    public function show(Request $request): Response
    {
        return $this->render($request, $this->submission($request));
    }

    public function decide(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $submission = $this->submission($request);
        $decision = $request->input('decision', '') ?? '';
        $feedback = $request->input('feedback', '') ?? '';
        try {
            $reviewed = $this->assignments->review(
                $tenant,
                RequestContext::user($request),
                $submission->id,
                $decision,
                $feedback,
                $request->baseUrl(),
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            return $this->render($request, $submission, $e->errors, ['feedback' => $feedback], 422);
        }
        $learner = $this->users->findById($reviewed->userId);
        $this->session->flash('success', sprintf(
            '%s: %s.',
            $reviewed->isApproved() ? 'Approved' : 'Changes requested',
            $learner->displayName ?? 'learner',
        ));
        $next = $this->submissions->queue($tenant->id, 1)[0] ?? null;

        return Response::redirect($next === null ? '/teach/reviews' : '/teach/reviews/' . $next['id']);
    }

    private function submission(Request $request): Submission
    {
        $tenant = RequestContext::tenant($request);

        return $this->submissions->find($tenant->id, $request->routeInt('submission')) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, Submission $submission, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $lesson = $this->lessons->findById($tenant->id, $submission->lessonId) ?? throw HttpException::notFound();
        $course = $this->courses->findById($tenant->id, $lesson->courseId) ?? throw HttpException::notFound();
        $history = array_values(array_filter(
            $this->submissions->history($tenant->id, $submission->enrolmentId, $submission->lessonId),
            static fn (Submission $s): bool => $s->id !== $submission->id,
        ));
        $reviewerNames = [];
        foreach (array_merge([$submission], $history) as $s) {
            if ($s->reviewedBy !== null && !isset($reviewerNames[$s->reviewedBy])) {
                $reviewerNames[$s->reviewedBy] = $this->users->findById($s->reviewedBy)->displayName ?? 'A teacher';
            }
        }

        return Response::html($this->view->render('teach/review', [
            'pageTitle' => 'Review: ' . $lesson->title,
            'submission' => $submission,
            'learner' => $this->users->findById($submission->userId),
            'lesson' => $lesson,
            'course' => $course,
            'history' => $history,
            'reviewerNames' => $reviewerNames,
            'isOwnWork' => $submission->userId === RequestContext::user($request)->user->id,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
