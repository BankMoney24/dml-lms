<?php

declare(strict_types=1);

namespace App\Modules\Exams\Http;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Cbt\CbtAttempt;
use App\Modules\Exams\Cbt\CbtExam;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Exams\Cbt\CbtRepository;

/**
 * Exams for learners (ADR 0042): the list, the start page, the exam room and
 * its JSON (paper, save, submit), results, and practice tests.
 */
final class CbtController
{
    public function __construct(
        private readonly View $view,
        private readonly CbtExams $service,
        private readonly CbtRepository $exams,
        private readonly BankRepository $banks,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function index(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $who = RequestContext::user($request);
        $now = $this->clock->sqlNow();
        $rows = [];
        foreach ($this->exams->examsFor($tenant->id, $who->user->id) as $exam) {
            $attempts = $this->exams->attemptsAt($tenant->id, $exam->id, $who->user->id);
            $rows[] = ['exam' => $exam, 'attempts' => $attempts, 'open' => $this->service->openAttempt($tenant, $who->user->id, $exam), 'why' => $this->service->cannotStart($tenant, $who, $exam)];
        }

        return Response::html($this->view->render('exams/learner_index', [
            'pageTitle' => 'Exams and quizzes',
            'rows' => $rows,
            'now' => $now,
            'practiceBanks' => $this->banks->banks($tenant->id, false, true),
            'practice' => $this->exams->practiceFor($tenant->id, $who->user->id),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /** @param array<string, string> $errors */
    public function show(Request $request, array $errors = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $who = RequestContext::user($request);
        $exam = $this->visibleExam($request);
        $banks = [];
        foreach ($exam->sections as $s) {
            $banks[$s['bank']] = true;
        }

        return Response::html($this->view->render('exams/learner_exam', [
            'pageTitle' => $exam->title,
            'exam' => $exam,
            'attempts' => $this->exams->attemptsAt($tenant->id, $exam->id, $who->user->id),
            'open' => $this->service->openAttempt($tenant, $who->user->id, $exam),
            'why' => $this->service->cannotStart($tenant, $who, $exam),
            'now' => $this->clock->sqlNow(),
            'clockNow' => $this->clock->now(),
            'errors' => $errors,
        ]), $status);
    }

    public function start(Request $request): Response
    {
        $exam = $this->visibleExam($request);
        $chosen = array_values(array_map('intval', $request->inputArray('subjects')));
        try {
            $id = $this->service->start(RequestContext::tenant($request), RequestContext::user($request), $exam, $chosen, (string) ($request->input('code', '') ?? ''));
        } catch (ValidationException $e) {
            return $this->show($request, $e->errors, 422);
        }

        return Response::redirect('/exams/attempts/' . $id);
    }

    public function practice(Request $request): Response
    {
        $banks = array_values(array_map('intval', $request->inputArray('banks')));
        $count = (int) ($request->input('count', '0') ?? '0');
        $minutes = (int) ($request->input('minutes', '0') ?? '0');
        try {
            $id = $this->service->startPractice(RequestContext::tenant($request), RequestContext::user($request), $banks, $count, $minutes);
        } catch (ValidationException $e) {
            return $this->index($request, $e->errors, ['banks' => $banks, 'count' => (string) $count, 'minutes' => (string) $minutes], 422);
        }

        return Response::redirect('/exams/attempts/' . $id);
    }

    /** The exam room: one full screen, no menus. The paper itself comes from paper() as JSON. */
    public function room(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $attempt = $this->mine($request);
        if (!$attempt->isSubmitted() && $attempt->isOverdue($this->clock->now())) {
            $attempt = $this->service->submit($tenant, $attempt->id, 'time') ?? $attempt;
        }
        if ($attempt->isSubmitted()) {
            return Response::redirect('/exams/attempts/' . $attempt->id . '/result');
        }
        $exam = $attempt->examId === null ? null : $this->exams->findExam($tenant->id, $attempt->examId);

        return Response::html($this->view->render('exams/room', [
            'pageTitle' => $attempt->title,
            'attempt' => $attempt,
            'exam' => $exam,
            'candidate' => RequestContext::user($request)->user->displayName,
            'focusClass' => 'focus-exam',
            'focusScript' => 'cbt.js',
            'exitHref' => null,
        ], 'layouts/focus'))->withHeader('Cache-Control', 'no-store');
    }

    public function paper(Request $request): Response
    {
        $attempt = $this->mine($request);

        return Response::json($this->service->paper(RequestContext::tenant($request), $attempt))->withHeader('Cache-Control', 'no-store');
    }

    public function save(Request $request): Response
    {
        $attempt = $this->mine($request);
        $data = json_decode($request->rawBody, true);
        if (!is_array($data)) {
            return Response::json(['ok' => false, 'error' => 'bad_request'], 400);
        }
        $this->service->recordAway(RequestContext::tenant($request), $attempt->id, RequestContext::user($request)->user->id, $data['away'] ?? null);
        $result = $this->service->saveAnswers(
            RequestContext::tenant($request),
            $attempt->id,
            RequestContext::user($request)->user->id,
            (int) ($data['seq'] ?? 0),
            is_array($data['answers'] ?? null) ? $data['answers'] : [],
            array_values(array_map('intval', is_array($data['flags'] ?? null) ? $data['flags'] : [])),
        );

        return Response::json($result, $result['ok'] || ($result['error'] ?? '') === 'closed' ? 200 : 404);
    }

    public function submit(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $attempt = $this->mine($request);
        $reason = $request->input('reason') === 'time' ? 'time' : 'candidate';
        $data = json_decode($request->rawBody, true);
        if (is_array($data)) {
            $this->service->recordAway($tenant, $attempt->id, $attempt->userId, $data['away'] ?? null);
        }
        if (is_array($data) && is_array($data['answers'] ?? null)) {
            // The last answers travel with the submission, so nothing chosen in the final seconds is lost.
            $flags = array_values(array_map('intval', is_array($data['flags'] ?? null) ? $data['flags'] : []));
            $this->service->saveAnswers($tenant, $attempt->id, $attempt->userId, (int) ($data['seq'] ?? 0), $data['answers'], $flags);
            $reason = ($data['reason'] ?? '') === 'time' ? 'time' : $reason;
        }
        $this->service->submit($tenant, $attempt->id, $reason);
        $url = '/exams/attempts/' . $attempt->id . '/result';
        if (str_contains((string) $request->header('accept'), 'application/json')) {
            return Response::json(['ok' => true, 'redirect' => $url]);
        }

        return Response::redirect($url);
    }

    public function result(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $attempt = $this->mine($request);
        if (!$attempt->isSubmitted()) {
            if (!$attempt->isOverdue($this->clock->now())) {
                return Response::redirect('/exams/attempts/' . $attempt->id);
            }
            $attempt = $this->service->submit($tenant, $attempt->id, 'time') ?? $attempt;
        }
        $exam = $attempt->examId === null ? null : $this->exams->findExam($tenant->id, $attempt->examId);
        $now = $this->clock->sqlNow();
        $showScore = $exam === null || $exam->resultsVisible($now);
        $showReview = $showScore && ($exam === null || $exam->review);

        return Response::html($this->view->render('exams/cbt_review', CbtView::review($tenant, $attempt, $this->banks) + [
            'pageTitle' => 'Result: ' . $attempt->title,
            'exam' => $exam,
            'forTeacher' => false,
            'showScore' => $showScore,
            'showReview' => $showReview,
        ]));
    }

    private function mine(Request $request): CbtAttempt
    {
        $a = $this->exams->findAttempt(RequestContext::tenant($request)->id, $request->routeInt('attempt'));
        if ($a === null || $a->userId !== RequestContext::user($request)->user->id) {
            throw HttpException::notFound();
        }

        return $a;
    }

    private function visibleExam(Request $request): CbtExam
    {
        $tenant = RequestContext::tenant($request);
        $who = RequestContext::user($request);
        $exam = $this->exams->findExam($tenant->id, $request->routeInt('exam'));
        if ($exam === null || $exam->status === 'draft' || ($exam->audience === 'groups' && !$this->exams->inGroupOf($tenant->id, $exam->id, $who->user->id))) {
            throw HttpException::notFound();
        }

        return $exam;
    }
}
