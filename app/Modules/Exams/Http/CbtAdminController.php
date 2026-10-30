<?php

declare(strict_types=1);

namespace App\Modules\Exams\Http;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Cbt\CbtAttempt;
use App\Modules\Exams\Cbt\CbtExam;
use App\Modules\Exams\Cbt\CbtExams;
use App\Modules\Exams\Cbt\CbtRepository;
use App\Modules\Reports\Csv;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/** Teach → Quizzes and exams → CBT exams (ADR 0042). */
final class CbtAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CbtExams $service,
        private readonly CbtRepository $exams,
        private readonly BankRepository $banks,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('exams/cbt_list', [
            'pageTitle' => 'CBT exams',
            'rows' => $this->exams->exams($tenant->id),
            'now' => $this->clock->sqlNow(),
            'hasBanks' => $this->banks->banks($tenant->id) !== [],
        ]));
    }

    public function create(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $old = $request->query('layout') === 'utme' ? $this->utmeLayout($tenant) : [];

        return $this->renderForm($request, null, [], $old);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request);
        try {
            $id = $this->service->save(RequestContext::tenant($request), RequestContext::user($request), $input);
        } catch (ValidationException $e) {
            return $this->renderForm($request, null, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Saved as a draft. Check it, try it as a candidate would, then publish it.');

        return Response::redirect('/teach/exams/' . $id);
    }

    public function show(Request $request): Response
    {
        return $this->renderForm($request, $this->exam($request));
    }

    public function update(Request $request): Response
    {
        $exam = $this->exam($request);
        $input = $this->input($request);
        try {
            $this->service->save(RequestContext::tenant($request), RequestContext::user($request), $input, $exam);
        } catch (ValidationException $e) {
            return $this->renderForm($request, $exam, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Saved.');

        return Response::redirect('/teach/exams/' . $exam->id);
    }

    public function publish(Request $request): Response
    {
        $exam = $this->exam($request);
        try {
            $this->service->publish(RequestContext::tenant($request), RequestContext::user($request), $exam);
        } catch (ValidationException $e) {
            $this->session->flash('error', 'Not published yet. ' . ($e->errors['publish'] ?? ''));

            return Response::redirect('/teach/exams/' . $exam->id);
        }
        $this->session->flash('success', 'Published. Candidates find it under Exams' . ($exam->opensAt !== null ? ' when it opens' : '') . '.');

        return Response::redirect('/teach/exams/' . $exam->id);
    }

    public function close(Request $request): Response
    {
        $exam = $this->exam($request);
        $n = $this->service->close(RequestContext::tenant($request), RequestContext::user($request), $exam);
        $this->session->flash('success', 'Closed.' . ($n > 0 ? ' ' . $n . ' ' . ($n === 1 ? 'attempt in progress was' : 'attempts in progress were')
            . ' submitted with the answers saved so far.' : ''));

        return Response::redirect('/teach/exams/' . $exam->id . '/results');
    }

    public function reopen(Request $request): Response
    {
        $exam = $this->exam($request);
        $this->service->reopen(RequestContext::tenant($request), RequestContext::user($request), $exam);
        $this->session->flash('success', 'Open again.');

        return Response::redirect('/teach/exams/' . $exam->id);
    }

    /** A teacher sits the exam as a candidate would; the attempt is a practice one and counts nowhere. */
    public function trial(Request $request): Response
    {
        $exam = $this->exam($request);
        $chosen = array_slice($exam->optional(), 0, $exam->chooseCount);
        try {
            $id = $this->service->start(RequestContext::tenant($request), RequestContext::user($request), $exam, $chosen, '', true);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/teach/exams/' . $exam->id);
        }

        return Response::redirect('/exams/attempts/' . $id);
    }

    public function results(Request $request): Response
    {
        $exam = $this->exam($request);
        $tenant = RequestContext::tenant($request);
        $attempts = $this->exams->attemptsForExam($tenant->id, $exam->id);

        return Response::html($this->view->render('exams/cbt_results', [
            'pageTitle' => 'Results: ' . $exam->title,
            'exam' => $exam,
            'attempts' => $attempts,
            'items' => $this->service->itemAnalysis($tenant, $attempts),
            'now' => $this->clock->now(),
        ]));
    }

    public function csv(Request $request): Response
    {
        $exam = $this->exam($request);
        $tenant = RequestContext::tenant($request);
        $labels = array_column($exam->sections, 'label');
        $rows = [];
        foreach ($this->exams->attemptsForExam($tenant->id, $exam->id) as $a) {
            $scores = [];
            foreach ($a->result['sections'] ?? [] as $s) {
                $scores[$s['label']] = $s['scaled'];
            }
            $rows[] = [
                $a->userName, $a->userEmail, $this->local($tenant, $a->startedAt), $a->submittedAt === null ? '' : $this->local($tenant, $a->submittedAt),
                $a->submittedAt === null ? 'in progress' : (string) $a->submitReason,
                ...array_map(static fn (string $l): ?int => $scores[$l] ?? null, $labels),
                $a->result['total'] ?? null, $a->result['max'] ?? null, $a->correctCount, $a->questionCount, $a->percent,
            ];
        }
        $file = preg_replace('/[^a-z0-9-]+/', '-', strtolower($exam->title)) . '-' . $this->clock->now()->format('Y-m-d') . '.csv';

        return new Response(Csv::build(['candidate', 'email', 'started', 'submitted', 'how', ...array_map(static fn (string $l): string => $l . ' (/100)', $labels), 'total',
            'out of', 'right', 'questions', 'percent'], $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . trim((string) $file, '-') . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function extend(Request $request): Response
    {
        $exam = $this->exam($request);
        $attempt = $this->attemptOf($request, $exam);
        try {
            $this->service->extend(RequestContext::tenant($request), RequestContext::user($request), $attempt, (int) ($request->input('minutes', '0') ?? '0'));
            $this->session->flash('success', 'Time added for ' . $attempt->userName . '. Their timer updates within a few seconds.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/teach/exams/' . $exam->id . '/results');
    }

    public function reset(Request $request): Response
    {
        $exam = $this->exam($request);
        $attempt = $this->attemptOf($request, $exam);
        $this->service->reset(RequestContext::tenant($request), RequestContext::user($request), $attempt);
        $this->session->flash('success', $attempt->userName . '’s attempt was removed; they can start again. This is recorded in Activity.');

        return Response::redirect('/teach/exams/' . $exam->id . '/results');
    }

    private function attemptOf(Request $request, CbtExam $exam): CbtAttempt
    {
        $a = $this->exams->findAttempt(RequestContext::tenant($request)->id, $request->routeInt('attempt'));
        if ($a === null || $a->examId !== $exam->id) {
            throw HttpException::notFound();
        }

        return $a;
    }

    public function review(Request $request): Response
    {
        $exam = $this->exam($request);
        $attempt = $this->attemptOf($request, $exam);

        return Response::html($this->view->render('exams/cbt_review', CbtView::review(RequestContext::tenant($request), $attempt, $this->banks) + [
            'pageTitle' => $attempt->userName . ': ' . $exam->title,
            'exam' => $exam,
            'forTeacher' => true,
            'showReview' => true,
            'showScore' => true,
        ]));
    }

    private function exam(Request $request): CbtExam
    {
        return $this->exams->findExam(RequestContext::tenant($request)->id, $request->routeInt('exam')) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $sections = [];
        $banks = $request->inputArray('section_bank');
        $counts = $request->inputArray('section_count');
        $labels = $request->inputArray('section_label');
        $compulsory = $request->inputArray('section_compulsory');
        foreach ($banks as $i => $bank) {
            $sections[] = [
                'bank' => (string) $bank, 'count' => (string) ($counts[$i] ?? ''), 'label' => (string) ($labels[$i] ?? ''),
                'compulsory' => in_array((string) $i, array_map('strval', $compulsory), true) ? '1' : '',
            ];
        }
        $out = ['sections' => $sections, 'groups' => $request->inputArray('groups')];
        $fields = [
            'title', 'instructions', 'choose_count', 'duration_minutes', 'opens_date', 'opens_time', 'closes_date', 'closes_time',
            'audience', 'max_attempts', 'results', 'review', 'shuffle_questions', 'shuffle_options', 'access_code',
        ];
        foreach ($fields as $k) {
            $out[$k] = $request->input($k, '') ?? '';
        }

        return $out;
    }

    /** @return array<string, mixed> a starting point shaped like the UTME: English compulsory, three of the rest, 120 minutes */
    private function utmeLayout(Tenant $tenant): array
    {
        $sections = [];
        foreach ($this->banks->banks($tenant->id) as $b) {
            $english = preg_match('/english/i', $b->name) === 1;
            // UTME papers have 60 English questions and 40 in each other subject; a smaller bank gives what it has.
            $count = min($english ? 60 : 40, max(1, $b->activeCount));
            $sections[] = ['bank' => (string) $b->id, 'count' => (string) $count, 'label' => (string) preg_replace('/\s*\(sample\)$/i', '', $b->name), 'compulsory' => $english ? '1' : ''];
        }
        usort($sections, static fn (array $a, array $b): int => (int) ($b['compulsory'] === '1') <=> (int) ($a['compulsory'] === '1'));
        $optional = count(array_filter($sections, static fn (array $s): bool => $s['compulsory'] !== '1'));

        return [
            'title' => 'Mock UTME', 'sections' => array_slice($sections, 0, 8), 'choose_count' => (string) min(3, max(0, $optional - 1)), 'duration_minutes' => '120',
            'results' => 'immediately', 'review' => '1', 'shuffle_questions' => '1', 'shuffle_options' => '1', 'max_attempts' => '1',
            'instructions' => "Answer every question. Each subject is scored out of 100.\n"
                . "Use the keys A to E to choose an answer, N for the next question, P for the previous one, F to flag a question to come back to, and S to submit.\n"
                . 'The exam submits itself when the time is up.',
        ];
    }

    private function local(Tenant $tenant, string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant->timezone))->format('Y-m-d H:i');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderForm(Request $request, ?CbtExam $exam, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $banks = $this->banks->banks($tenant->id);

        return Response::html($this->view->render('exams/cbt_form', [
            'pageTitle' => $exam === null ? 'New CBT exam' : $exam->title,
            'exam' => $exam,
            'banks' => $banks,
            'groups' => $this->exams->groups($tenant->id),
            'problems' => $exam === null ? [] : $this->service->problems($tenant, $exam),
            'attemptCount' => $exam === null ? 0 : count($this->exams->attemptsForExam($tenant->id, $exam->id)),
            'tz' => $tenant->timezone,
            'errors' => $errors,
            'old' => $old,
            'now' => $this->clock->sqlNow(),
        ]), $status);
    }
}
