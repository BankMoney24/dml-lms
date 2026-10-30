<?php

declare(strict_types=1);

namespace App\Modules\Exams\Http;

use App\Modules\Exams\StarterKit\ExamStarterKit;
use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Live\LiveGame;
use App\Modules\Exams\Live\LiveQuestion;
use App\Modules\Exams\Live\LiveQuiz;
use App\Modules\Exams\Live\LiveRepository;
use App\Modules\Exams\Live\LiveSession;
use App\Modules\Reports\Csv;

/** Teach → Quizzes and exams → Live quizzes: writing them, and running a game on the class screen (ADR 0042). */
final class LiveAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly LiveGame $game,
        private readonly LiveRepository $live,
        private readonly BankRepository $banks,
        private readonly Clock $clock,
        private readonly ExamStarterKit $kit,
    ) {
    }

    /** @param array<string, string> $errors */
    public function index(Request $request, array $errors = [], string $title = '', int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('exams/live_list', [
            'pageTitle' => 'Live quizzes',
            'quizzes' => $this->live->quizzes($tenant->id),
            'kit' => $this->kit->summary($tenant),
            'errors' => $errors,
            'title' => $title,
        ]), $status);
    }

    public function store(Request $request): Response
    {
        $title = (string) ($request->input('title', '') ?? '');
        try {
            $id = $this->game->createQuiz(RequestContext::tenant($request), RequestContext::user($request), $title);
        } catch (ValidationException $e) {
            return $this->index($request, $e->errors, $title, 422);
        }

        return Response::redirect('/teach/live/' . $id);
    }

    public function edit(Request $request): Response
    {
        return $this->renderQuiz($request, $this->quiz($request));
    }

    public function rename(Request $request): Response
    {
        $quiz = $this->quiz($request);
        try {
            $this->game->renameQuiz(RequestContext::tenant($request), RequestContext::user($request), $quiz, (string) ($request->input('title', '') ?? ''));
        } catch (ValidationException $e) {
            return $this->renderQuiz($request, $quiz, ['title' => $e->errors], [], 422);
        }
        $this->session->flash('success', 'Renamed.');

        return Response::redirect('/teach/live/' . $quiz->id);
    }

    public function archive(Request $request): Response
    {
        $quiz = $this->quiz($request);
        $this->game->archive(RequestContext::tenant($request), RequestContext::user($request), $quiz, $quiz->archivedAt === null);
        $this->session->flash('success', $quiz->archivedAt === null ? 'Archived “' . $quiz->title . '”.' : 'Back in your list.');

        return Response::redirect('/teach/live');
    }

    public function addQuestion(Request $request): Response
    {
        $quiz = $this->quiz($request);
        $input = $this->questionInput($request);
        try {
            $this->game->saveQuestion(RequestContext::tenant($request), RequestContext::user($request), $quiz, $input);
        } catch (ValidationException $e) {
            return $this->renderQuiz($request, $quiz, ['question' => $e->errors], ['question' => $input], 422);
        }
        $this->session->flash('success', 'Question added.');

        return Response::redirect('/teach/live/' . $quiz->id . '#add');
    }

    public function editQuestion(Request $request): Response
    {
        $quiz = $this->quiz($request);

        return $this->renderQuestion($request, $quiz, $this->question($request, $quiz));
    }

    public function updateQuestion(Request $request): Response
    {
        $quiz = $this->quiz($request);
        $question = $this->question($request, $quiz);
        $input = $this->questionInput($request);
        try {
            $this->game->saveQuestion(RequestContext::tenant($request), RequestContext::user($request), $quiz, $input, $question);
        } catch (ValidationException $e) {
            return $this->renderQuestion($request, $quiz, $question, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Question saved.');

        return Response::redirect('/teach/live/' . $quiz->id . '#q' . $question->id);
    }

    public function deleteQuestion(Request $request): Response
    {
        $quiz = $this->quiz($request);
        $this->game->deleteQuestion(RequestContext::tenant($request), RequestContext::user($request), $quiz, $this->question($request, $quiz));
        $this->session->flash('success', 'Question deleted.');

        return Response::redirect('/teach/live/' . $quiz->id);
    }

    public function move(Request $request): Response
    {
        $quiz = $this->quiz($request);
        $question = $this->question($request, $quiz);
        $this->game->move(RequestContext::tenant($request), RequestContext::user($request), $quiz, $question, $request->input('to') === 'up' ? -1 : 1);

        return Response::redirect('/teach/live/' . $quiz->id . '#q' . $question->id);
    }

    public function fromBank(Request $request): Response
    {
        $quiz = $this->quiz($request);
        $input = ['bank' => (string) ($request->input('bank', '') ?? ''), 'count' => (string) ($request->input('count', '') ?? ''), 'seconds' => (string) ($request->input('seconds', '20') ?? '20')];
        try {
            $n = $this->game->addFromBank(RequestContext::tenant($request), RequestContext::user($request), $quiz, (int) $input['bank'], (int) $input['count'], (int) $input['seconds']);
        } catch (ValidationException $e) {
            return $this->renderQuiz($request, $quiz, ['bank' => $e->errors], ['bank' => $input], 422);
        }
        $this->session->flash('success', 'Added ' . $n . ' ' . ($n === 1 ? 'question' : 'questions') . ' from the bank. Check them before you play.');

        return Response::redirect('/teach/live/' . $quiz->id);
    }

    public function host(Request $request): Response
    {
        $quiz = $this->quiz($request);
        try {
            $id = $this->game->host(RequestContext::tenant($request), RequestContext::user($request), $quiz, $request->input('guests') === '1', $request->input('fun_names') === '1');
        } catch (ValidationException $e) {
            return $this->renderQuiz($request, $quiz, ['host' => $e->errors], [], 422);
        }

        return Response::redirect('/teach/live/games/' . $id);
    }

    /** The class screen. */
    public function stage(Request $request): Response
    {
        $s = $this->session($request);
        if ($s->status === 'finished' && $request->query('podium') !== '1') {
            return Response::redirect('/teach/live/games/' . $s->id . '/results');
        }

        return Response::html($this->view->render('exams/live_stage', [
            'pageTitle' => $s->title,
            'game' => $s,
            'joinUrl' => RequestContext::baseUrl($request) . '/play',
            'host' => (string) preg_replace('#^https?://#', '', RequestContext::baseUrl($request)),
            'focusClass' => 'focus-stage',
            'focusScript' => 'live.js',
            'exitHref' => '/teach/live/' . $s->quizId,
            'exitLabel' => 'Back to the quiz',
        ], 'layouts/focus'))->withHeader('Cache-Control', 'no-store');
    }

    public function state(Request $request): Response
    {
        return Response::json($this->game->hostState(RequestContext::tenant($request), $this->session($request)))->withHeader('Cache-Control', 'no-store');
    }

    public function advance(Request $request): Response
    {
        $s = $this->session($request);
        $action = (string) ($request->input('action', '') ?? '');
        if (in_array($action, ['start', 'reveal', 'next', 'end'], true)) {
            $this->game->advance(RequestContext::tenant($request), RequestContext::user($request), $s->id, $action);
        }
        $fresh = $this->live->findSession(RequestContext::tenant($request)->id, $s->id) ?? $s;

        return Response::json($this->game->hostState(RequestContext::tenant($request), $fresh));
    }

    public function remove(Request $request): Response
    {
        $s = $this->session($request);
        $this->game->removePlayer(RequestContext::tenant($request), RequestContext::user($request), $s, (int) ($request->input('player', '0') ?? '0'));

        return Response::json($this->game->hostState(RequestContext::tenant($request), $s));
    }

    public function results(Request $request): Response
    {
        $s = $this->session($request);

        return Response::html($this->view->render('exams/live_results', [
            'pageTitle' => 'Results: ' . $s->title,
            'game' => $s,
            'results' => $this->game->results(RequestContext::tenant($request), $s),
        ]));
    }

    public function csv(Request $request): Response
    {
        $s = $this->session($request);
        $r = $this->game->results(RequestContext::tenant($request), $s);
        $header = ['rank', 'player', 'member', 'score', 'right'];
        foreach (array_keys($s->questions) as $i) {
            $header[] = 'Q' . ($i + 1);
        }
        $rows = [];
        foreach ($r['players'] as $row) {
            $p = $row['player'];
            $cells = [$row['rank'], $p->nickname, $p->userId === null ? 'guest' : 'member', $p->score, $p->correctCount];
            foreach (array_keys($s->questions) as $i) {
                $a = $row['answers'][$i] ?? null;
                $cells[] = $a === null ? '' : chr(65 + $a['choice']) . ($a['correct'] ? ' (right)' : '');
            }
            $rows[] = $cells;
        }
        $file = preg_replace('/[^a-z0-9-]+/', '-', strtolower($s->title)) . '-' . $this->clock->now()->format('Y-m-d') . '.csv';

        return new Response(Csv::build($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . trim((string) $file, '-') . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function quiz(Request $request): LiveQuiz
    {
        return $this->live->findQuiz(RequestContext::tenant($request)->id, $request->routeInt('quiz')) ?? throw HttpException::notFound();
    }

    private function question(Request $request, LiveQuiz $quiz): LiveQuestion
    {
        return $this->live->findQuestion(RequestContext::tenant($request)->id, $quiz->id, $request->routeInt('question')) ?? throw HttpException::notFound();
    }

    /** A game in this school: any teacher may watch it and see its results; only its host moves it on. */
    private function session(Request $request): LiveSession
    {
        $s = $this->live->findSession(RequestContext::tenant($request)->id, $request->routeInt('game'));
        if ($s === null) {
            throw HttpException::notFound();
        }

        return $s;
    }

    /** @return array<string, mixed> */
    private function questionInput(Request $request): array
    {
        return [
            'prompt' => $request->input('prompt', ''), 'options' => $request->inputArray('options'), 'correct' => $request->input('correct', ''),
            'seconds' => $request->input('seconds', '20'), 'points' => $request->input('points', 'standard'),
        ];
    }

    /**
     * @param array<string, array<string, string>> $errors by form: title, question, bank, host
     * @param array<string, array<string, mixed>> $old
     */
    private function renderQuiz(Request $request, LiveQuiz $quiz, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('exams/live_quiz', [
            'pageTitle' => $quiz->title,
            'quiz' => $quiz,
            'questions' => $this->live->questions($tenant->id, $quiz->id),
            'games' => $this->live->sessionsFor($tenant->id, $quiz->id),
            'banks' => $this->banks->banks($tenant->id),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderQuestion(Request $request, LiveQuiz $quiz, LiveQuestion $question, array $errors = [], array $old = [], int $status = 200): Response
    {
        return Response::html($this->view->render('exams/live_question', [
            'pageTitle' => 'Edit question',
            'quiz' => $quiz,
            'question' => $question,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
