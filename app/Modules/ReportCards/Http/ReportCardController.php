<?php

declare(strict_types=1);

namespace App\Modules\ReportCards\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Groups\GroupRepository;
use App\Modules\Groups\GroupService;
use App\Modules\Groups\LearnerGroup;
use App\Modules\Guardians\GuardianService;
use App\Modules\Reports\Csv;
use App\Modules\ReportCards\Grading;
use App\Modules\ReportCards\ReportCard;
use App\Modules\ReportCards\ReportCardRepository;
use App\Modules\ReportCards\ReportCards;
use App\Modules\Tenancy\Tenant;

/**
 * Report cards (ADR 0046): set up and fill in for a class (its teachers and those who
 * manage every class), and read once published (the learner and parents who may see results).
 */
final class ReportCardController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly ReportCards $service,
        private readonly ReportCardRepository $cards,
        private readonly GroupService $groupService,
        private readonly GroupRepository $groups,
        private readonly ?GuardianService $guardians = null,
    ) {
    }

    /* ---------- for the class's teachers ---------- */

    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    public function store(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $input = $this->input($request);
        try {
            $id = $this->service->create($tenant, $group, RequestContext::user($request), $input);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Report card made. Check the scores, write the remarks, then share it with the class.');

        return Response::redirect('/groups/' . $group->id . '/report-cards/' . $id);
    }

    public function show(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);

        return Response::html($this->view->render('report_cards/show', [
            'pageTitle' => $card->title,
            'group' => $group,
            'card' => $card,
            'learners' => $learners,
            'sheet' => $this->service->sheet($tenant, $card, $learners),
            'remarks' => $this->cards->remarks($tenant->id, $card->id),
        ]));
    }

    public function edit(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);

        return $this->renderForm($request, $tenant, $group, $card);
    }

    public function update(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $input = $this->input($request);
        try {
            $this->service->update($tenant, $group, $card, RequestContext::user($request), $input);
        } catch (ValidationException $e) {
            return $this->renderForm($request, $tenant, $group, $card, $e->errors, $input, 422);
        }
        $this->session->flash('success', 'Saved.');

        return Response::redirect('/groups/' . $group->id . '/report-cards/' . $card->id);
    }

    public function publish(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $this->service->publish($tenant, $group, $card, RequestContext::user($request));
        $this->session->flash('success', 'Shared. Each learner sees their own card, and so do parents who may see results.');

        return Response::redirect('/groups/' . $group->id . '/report-cards/' . $card->id);
    }

    public function unpublish(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $this->service->unpublish($tenant, $group, $card, RequestContext::user($request));
        $this->session->flash('success', 'Taken back. Learners and parents no longer see it.');

        return Response::redirect('/groups/' . $group->id . '/report-cards/' . $card->id);
    }

    public function delete(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        try {
            $this->service->delete($tenant, $group, $card, RequestContext::user($request));
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/groups/' . $group->id . '/report-cards/' . $card->id);
        }
        $this->session->flash('success', 'Report card deleted.');

        return Response::redirect('/groups/' . $group->id . '/report-cards');
    }

    /**
     * One learner's card, with the remarks to write.
     *
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    public function learner(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);
        $ids = array_column($learners, 'user_id');
        $at = array_search($request->routeInt('learner'), $ids, true);
        if ($at === false) {
            throw HttpException::notFound();
        }

        return Response::html($this->view->render('report_cards/learner', [
            'pageTitle' => $learners[$at]['name'] . ': ' . $card->title,
            'school' => $tenant,
            'group' => $group,
            'card' => $card,
            'learner' => $learners[$at],
            'sheet' => $this->service->sheet($tenant, $card, $learners),
            'remarks' => $this->cards->remarks($tenant->id, $card->id)[$learners[$at]['user_id']] ?? ['teacher' => '', 'head' => ''],
            'mayWriteHead' => $this->groupService->managesAll(RequestContext::user($request)),
            'previous' => $at > 0 ? $learners[$at - 1] : null,
            'next' => $learners[$at + 1] ?? null,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    public function saveRemarks(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $learnerId = $request->routeInt('learner');
        $teacher = (string) ($request->input('teacher_remark', '') ?? '');
        $head = (string) ($request->input('head_remark', '') ?? '');
        try {
            $this->service->saveRemarks($tenant, $group, $card, RequestContext::user($request), $learnerId, $teacher, $head);
        } catch (ValidationException $e) {
            return $this->learner($request, $e->errors, ['teacher' => $teacher, 'head' => $head], 422);
        }
        $this->session->flash('success', 'Remarks saved.');
        $next = (int) ($request->input('next', '0') ?? '0');
        $base = '/groups/' . $group->id . '/report-cards/' . $card->id . '/learners/';

        return Response::redirect($next > 0 ? $base . $next : $base . $learnerId);
    }

    /** Every learner's card, one per printed page. */
    public function printAll(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);

        return Response::html($this->view->render('report_cards/print', [
            'pageTitle' => $card->title . ': all cards',
            'school' => $tenant,
            'group' => $group,
            'card' => $card,
            'learners' => $learners,
            'sheet' => $this->service->sheet($tenant, $card, $learners),
            'remarks' => $this->cards->remarks($tenant->id, $card->id),
        ]));
    }

    /** The broadsheet: a row per learner, a column per subject, then average and position. */
    public function csv(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $card = $this->card($request, $tenant, $group);
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);
        $sheet = $this->service->sheet($tenant, $card, $learners);
        $header = ['Learner', 'Email'];
        foreach ($sheet['subjects'] as $s) {
            $header[] = $s;
            $header[] = $s . ' grade';
            $header[] = $s . ' position';
        }
        array_push($header, 'Average', 'Position', 'Absent from');
        $rows = [];
        foreach ($learners as $l) {
            $r = $sheet['rows'][$l['user_id']];
            $row = [$l['name'], $l['email']];
            foreach ($sheet['subjects'] as $s) {
                $cell = $r['subjects'][$s] ?? null;
                array_push($row, $cell['score'] ?? null, $cell['grade'] ?? null, isset($cell['position']) ? Grading::ordinal($cell['position']) : null);
            }
            array_push($row, $r['average'] === null ? null : number_format($r['average'], 1), $r['position'] === null ? null : Grading::ordinal($r['position']), implode('; ', $r['absent']));
            $rows[] = $row;
        }
        $file = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $group->name . ' ' . $card->title), '-');

        return new Response(Csv::build($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ($file === '' ? 'report-card' : $file) . '.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /* ---------- for learners and parents ---------- */

    /** The learner's published report cards. */
    public function mine(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('report_cards/mine', [
            'pageTitle' => 'Report cards',
            'cards' => $this->cards->publishedFor($tenant->id, RequestContext::user($request)->user->id),
        ]));
    }

    public function myCard(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);

        return $this->readOnly($tenant, $request->routeInt('card'), $me->user->id, $me->user->displayName, '/report-cards', 'Report cards');
    }

    public function childCard(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $link = $this->guardians?->viewLink($tenant, RequestContext::user($request), $request->routeInt('learner')) ?? throw HttpException::notFound();
        if (!$link->canResults) {
            throw HttpException::notFound();
        }

        return $this->readOnly($tenant, $request->routeInt('card'), $link->learnerId, $link->learnerName, '/children/' . $link->learnerId, $link->learnerName);
    }

    private function readOnly(Tenant $tenant, int $cardId, int $learnerId, string $name, string $back, string $backLabel): Response
    {
        $card = $this->cards->find($tenant->id, $cardId);
        if ($card === null || !$card->isPublished()) {
            throw HttpException::notFound();
        }
        $learners = $this->groups->members($tenant->id, $card->groupId, GroupRepository::LEARNER);
        $me = array_values(array_filter($learners, static fn (array $l): bool => $l['user_id'] === $learnerId));
        if ($me === []) {
            throw HttpException::notFound();
        }

        return Response::html($this->view->render('report_cards/read', [
            'pageTitle' => $card->title,
            'school' => $tenant,
            'card' => $card,
            'learner' => $me[0] + ['name' => $name],
            'sheet' => $this->service->sheet($tenant, $card, $learners),
            'remarks' => $this->cards->remarks($tenant->id, $card->id)[$learnerId] ?? ['teacher' => '', 'head' => ''],
            'back' => $back,
            'backLabel' => $backLabel,
        ]));
    }

    /* ---------- helpers ---------- */

    /** @return array{0: Tenant, 1: LearnerGroup} */
    private function group(Request $request): array
    {
        $tenant = RequestContext::tenant($request);

        return [$tenant, $this->groupService->groupFor($tenant, $request->routeInt('group'), RequestContext::user($request))];
    }

    private function card(Request $request, Tenant $tenant, LearnerGroup $group): ReportCard
    {
        $card = $this->cards->find($tenant->id, $request->routeInt('card'));
        if ($card === null || $card->groupId !== $group->id) {
            throw HttpException::notFound();
        }

        return $card;
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return [
            'title' => $request->input('title', '') ?? '',
            'grading' => $request->input('grading', 'waec') ?? 'waec',
            'next_term' => $request->input('next_term', '') ?? '',
            'exam' => $request->inputArray('exam'),
            'label' => $request->inputArray('label'),
            'weight' => $request->inputArray('weight'),
        ];
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderIndex(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$tenant, $group] = $this->group($request);

        return Response::html($this->view->render('report_cards/index', [
            'pageTitle' => 'Report cards',
            'group' => $group,
            'cards' => $this->cards->forGroup($tenant->id, $group->id),
            'exams' => $this->service->examsFor($tenant, $group),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderForm(Request $request, Tenant $tenant, LearnerGroup $group, ReportCard $card, array $errors = [], array $old = [], int $status = 200): Response
    {
        return Response::html($this->view->render('report_cards/edit', [
            'pageTitle' => 'Change ' . $card->title,
            'group' => $group,
            'card' => $card,
            'exams' => $this->service->examsFor($tenant, $group),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
