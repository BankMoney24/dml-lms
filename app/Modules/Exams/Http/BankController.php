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
use App\Modules\Exams\Bank\BankQuestion;
use App\Modules\Exams\Bank\BankRepository;
use App\Modules\Exams\Bank\QuestionBank;
use App\Modules\Exams\Bank\QuestionBanks;
use App\Modules\Exams\Bank\SampleBanks;
use App\Modules\Reports\Csv;

/** Teach → Tests → Question banks (ADR 0042). */
final class BankController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly QuestionBanks $service,
        private readonly BankRepository $banks,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    public function store(Request $request): Response
    {
        $input = ['name' => $request->input('name', ''), 'description' => $request->input('description', ''), 'practice' => $request->input('practice', '')];
        try {
            $id = $this->service->createBank(RequestContext::tenant($request), RequestContext::user($request), $input);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Bank made. Add questions one at a time, or import many at once.');

        return Response::redirect('/teach/banks/' . $id);
    }

    public function samples(Request $request): Response
    {
        $added = $this->service->addSamples(RequestContext::tenant($request), RequestContext::user($request));
        $this->session->flash('success', $added === 0
            ? 'The sample banks are already here.'
            : 'Added ' . $added . ' sample ' . ($added === 1 ? 'bank' : 'banks')
                . ', open for practice. They are practice questions written for this site, not past exam questions: check them, edit them and add your own.');

        return Response::redirect('/teach/banks');
    }

    public function show(Request $request): Response
    {
        return $this->renderBank($request, $this->bank($request));
    }

    public function update(Request $request): Response
    {
        $bank = $this->bank($request);
        $input = ['name' => $request->input('name', ''), 'description' => $request->input('description', ''), 'practice' => $request->input('practice', '')];
        try {
            $this->service->updateBank(RequestContext::tenant($request), RequestContext::user($request), $bank, $input);
        } catch (ValidationException $e) {
            return $this->renderBank($request, $bank, ['settings' => $e->errors], ['settings' => $input], 422);
        }
        $this->session->flash('success', 'Saved.');

        return Response::redirect('/teach/banks/' . $bank->id);
    }

    public function archive(Request $request): Response
    {
        $bank = $this->bank($request);
        $this->service->archive(RequestContext::tenant($request), RequestContext::user($request), $bank, $bank->archivedAt === null);
        $this->session->flash('success', $bank->archivedAt === null ? 'Archived. Exams already set keep their questions.' : 'Back in use.');

        return Response::redirect($bank->archivedAt === null ? '/teach/banks' : '/teach/banks/' . $bank->id);
    }

    public function newQuestion(Request $request): Response
    {
        return $this->renderQuestion($request, $this->bank($request), null);
    }

    public function storeQuestion(Request $request): Response
    {
        $bank = $this->bank($request);
        $input = $this->questionInput($request);
        try {
            $this->service->addQuestion(RequestContext::tenant($request), RequestContext::user($request), $bank, $input);
        } catch (ValidationException $e) {
            return $this->renderQuestion($request, $bank, null, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Question added.');

        return Response::redirect('/teach/banks/' . $bank->id . ($request->input('another') === '1' ? '/questions/new' : ''));
    }

    public function editQuestion(Request $request): Response
    {
        $bank = $this->bank($request);

        return $this->renderQuestion($request, $bank, $this->question($request, $bank));
    }

    public function updateQuestion(Request $request): Response
    {
        $bank = $this->bank($request);
        $question = $this->question($request, $bank);
        $input = $this->questionInput($request);
        try {
            $this->service->editQuestion(RequestContext::tenant($request), RequestContext::user($request), $bank, $question, $input);
        } catch (ValidationException $e) {
            return $this->renderQuestion($request, $bank, $question, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Question saved.');

        return Response::redirect('/teach/banks/' . $bank->id);
    }

    public function retire(Request $request): Response
    {
        $bank = $this->bank($request);
        $question = $this->question($request, $bank);
        $this->service->retire(RequestContext::tenant($request), RequestContext::user($request), $question, $question->retiredAt === null);
        $this->session->flash('success', $question->retiredAt === null ? 'Retired: it will not be drawn into new papers.' : 'Back in use.');

        return Response::redirect('/teach/banks/' . $bank->id . ($question->retiredAt === null ? '' : '?status=retired'));
    }

    public function import(Request $request): Response
    {
        $bank = $this->bank($request);
        $text = (string) ($request->input('questions', '') ?? '');
        $file = $request->file('file');
        $isCsv = false;
        if ($file !== null && $file->wasSent() && $file->isOk()) {
            if ($file->size > 1_000_000) {
                return $this->renderBank($request, $bank, ['import' => ['questions' => 'Choose a file under 1 MB.']], ['import' => ['questions' => $text]], 422);
            }
            $text = (string) file_get_contents($file->tmpPath);
            $isCsv = str_ends_with(strtolower($file->clientName), '.csv');
        }
        try {
            $n = $this->service->import(RequestContext::tenant($request), RequestContext::user($request), $bank, $text, $isCsv);
        } catch (ValidationException $e) {
            return $this->renderBank($request, $bank, ['import' => $e->errors], ['import' => ['questions' => $file === null ? $text : '']], 422);
        }
        $this->session->flash('success', 'Imported ' . $n . ' ' . ($n === 1 ? 'question' : 'questions') . '.');

        return Response::redirect('/teach/banks/' . $bank->id);
    }

    public function export(Request $request): Response
    {
        $bank = $this->bank($request);
        $tenant = RequestContext::tenant($request);
        $rows = array_map(static fn (BankQuestion $q): array => [
            $q->prompt, $q->options[0] ?? '', $q->options[1] ?? '', $q->options[2] ?? '', $q->options[3] ?? '', $q->options[4] ?? '',
            $q->correctLetter(), $q->explanation, $q->topic, $q->difficulty === null ? '' : strtolower(BankQuestion::DIFFICULTY[$q->difficulty]),
        ], $this->banks->allQuestions($tenant->id, $bank->id));
        $file = preg_replace('/[^a-z0-9-]+/', '-', strtolower($bank->name)) . '-' . $this->clock->now()->format('Y-m-d') . '.csv';

        return new Response(Csv::build(['question', 'a', 'b', 'c', 'd', 'e', 'answer', 'explanation', 'topic', 'difficulty'], $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . trim((string) $file, '-') . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storePassage(Request $request): Response
    {
        $bank = $this->bank($request);
        $input = ['title' => $request->input('title', ''), 'body' => $request->input('body', '')];
        try {
            $this->service->savePassage(RequestContext::tenant($request), RequestContext::user($request), $bank, $input);
        } catch (ValidationException $e) {
            return $this->renderBank($request, $bank, ['passage' => $e->errors], ['passage' => $input], 422);
        }
        $this->session->flash('success', 'Passage saved. Choose it when you write the questions that go with it.');

        return Response::redirect('/teach/banks/' . $bank->id . '#passages');
    }

    public function updatePassage(Request $request): Response
    {
        $bank = $this->bank($request);
        $tenant = RequestContext::tenant($request);
        $passage = $this->banks->findPassage($tenant->id, $request->routeInt('passage'));
        if ($passage === null || $passage->bankId !== $bank->id) {
            throw HttpException::notFound();
        }
        if ($request->input('delete') === '1') {
            $this->service->deletePassage($tenant, RequestContext::user($request), $passage);
            $this->session->flash('success', 'Passage deleted. Its questions stay, without it.');

            return Response::redirect('/teach/banks/' . $bank->id . '#passages');
        }
        $input = ['title' => $request->input('title', ''), 'body' => $request->input('body', '')];
        try {
            $this->service->savePassage($tenant, RequestContext::user($request), $bank, $input, $passage);
        } catch (ValidationException $e) {
            return $this->renderBank($request, $bank, ['passage' => $e->errors], ['passage' => $input + ['id' => (string) $passage->id]], 422);
        }
        $this->session->flash('success', 'Passage saved.');

        return Response::redirect('/teach/banks/' . $bank->id . '#passages');
    }

    private function bank(Request $request): QuestionBank
    {
        return $this->banks->findBank(RequestContext::tenant($request)->id, $request->routeInt('bank')) ?? throw HttpException::notFound();
    }

    private function question(Request $request, QuestionBank $bank): BankQuestion
    {
        $q = $this->banks->findQuestion(RequestContext::tenant($request)->id, $request->routeInt('question'));
        if ($q === null || $q->bankId !== $bank->id) {
            throw HttpException::notFound();
        }

        return $q;
    }

    /** @return array<string, mixed> */
    private function questionInput(Request $request): array
    {
        return [
            'prompt' => $request->input('prompt', ''), 'options' => $request->inputArray('options'), 'correct' => $request->input('correct', ''),
            'explanation' => $request->input('explanation', ''), 'topic' => $request->input('topic', ''), 'difficulty' => $request->input('difficulty', ''),
            'passage_id' => $request->input('passage_id', ''),
        ];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderIndex(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $banks = $this->banks->banks($tenant->id, true);
        $names = array_map(static fn (QuestionBank $b): string => mb_strtolower($b->name), $banks);
        $missing = array_filter(SampleBanks::all(), static fn (array $s): bool => !in_array(mb_strtolower($s['name']), $names, true));

        return Response::html($this->view->render('exams/banks', [
            'pageTitle' => 'Question banks',
            'banks' => $banks,
            'samplesMissing' => count($missing),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * @param array<string, array<string, string>> $errors by form
     * @param array<string, array<string, mixed>> $old by form
     */
    private function renderBank(Request $request, QuestionBank $bank, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $find = trim((string) ($request->query('q', '') ?? ''));
        $topic = (string) ($request->query('topic', '') ?? '');
        $state = in_array($request->query('status'), ['retired', 'all'], true) ? (string) $request->query('status') : 'active';
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        [$questions, $total] = $this->banks->questions($tenant->id, $bank->id, $find, $topic, $state, $page, 25);

        return Response::html($this->view->render('exams/bank', [
            'pageTitle' => $bank->name,
            'bank' => $bank,
            'questions' => $questions,
            'total' => $total,
            'page' => $page,
            'find' => $find,
            'topic' => $topic,
            'state' => $state,
            'topics' => $this->banks->topics($tenant->id, $bank->id),
            'passages' => $this->banks->passages($tenant->id, $bank->id),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderQuestion(Request $request, QuestionBank $bank, ?BankQuestion $question, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('exams/bank_question', [
            'pageTitle' => $question === null ? 'New question' : 'Edit question',
            'bank' => $bank,
            'question' => $question,
            'passages' => $this->banks->passages($tenant->id, $bank->id),
            'topics' => $this->banks->topics($tenant->id, $bank->id),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
