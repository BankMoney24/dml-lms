<?php

declare(strict_types=1);

namespace App\Modules\Groups\Http;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Groups\GroupProgress;
use App\Modules\Groups\GroupRepository;
use App\Modules\Groups\GroupService;
use App\Modules\Groups\LearnerGroup;
use App\Modules\Payments\Http\PaymentAdminController;
use App\Modules\Tenancy\Tenant;

/** The Classes (or Cohorts, Groups…) pages: list, one group with its progress, and the changes to it. */
final class GroupController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly GroupService $service,
        private readonly GroupRepository $groups,
        private readonly GroupProgress $progress,
        private readonly CourseRepository $courses,
        private readonly Clock $clock,
        private readonly ?\App\Modules\LiveClasses\LiveClasses $live = null,
        private readonly ?\App\Modules\LiveClasses\ClassSessionRepository $classes = null,
        private readonly ?\App\Modules\Exams\Cbt\CbtRepository $cbt = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->listPage($request);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function listPage(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        if (!$this->service->mayOpenList($user)) {
            throw HttpException::forbidden();
        }
        $all = $this->service->managesAll($user);
        $archived = $all && $request->query('archived') === '1';

        return Response::html($this->view->render('groups/index', [
            'pageTitle' => $tenant->groupWord(true, true),
            'school' => $tenant,
            'rows' => $this->groups->overview($tenant->id, $all ? null : $user->user->id, $archived),
            'managesAll' => $all,
            'showingArchived' => $archived,
            'archivedCount' => $all ? $this->groups->archivedCount($tenant->id) : 0,
            'unplaced' => $all ? count($this->groups->learnersWithoutGroup($tenant->id, 1000)) : 0,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $input = $this->details($request);
        try {
            $id = $this->service->create($tenant, RequestContext::user($request), $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->listPage($request, $e->errors, $input, 422);
        }
        $this->session->flash('success', sprintf('%s created. Now add learners and set courses.', $input['name']));

        return Response::redirect('/groups/' . $id);
    }

    public function show(Request $request): Response
    {
        return $this->render($request);
    }

    public function update(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $input = $this->details($request);
        try {
            $this->service->update($tenant, $group, RequestContext::user($request), $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $input + ['details_open' => '1'], 422);
        }
        $this->session->flash('success', 'Details saved.');

        return $this->back($group);
    }

    public function archive(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $restore = $request->input('restore') === '1';
        $this->service->setArchived($tenant, $group, RequestContext::user($request), !$restore, $request->clientIp);
        $this->session->flash('success', $restore
            ? sprintf('%s is back in the list.', $group->name)
            : sprintf('%s is archived. Its learners keep their courses and progress.', $group->name));

        return $restore ? $this->back($group) : Response::redirect('/groups');
    }

    public function addLearners(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $ids = array_values(array_filter(array_map('intval', array_values($request->inputArray('learners'))), static fn (int $id): bool => $id > 0));
        try {
            $result = $this->service->addLearners($tenant, $group, RequestContext::user($request), $request->input('emails', '') ?? '', $ids, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, ['emails' => $request->input('emails', '') ?? ''], 422);
        }
        $parts = [];
        if ($result['added'] !== []) {
            $parts[] = sprintf('Added %s.', $this->names($result['added']));
        }
        if ($result['enrolments'] > 0) {
            $parts[] = sprintf('%d new course %s made.', $result['enrolments'], $result['enrolments'] === 1 ? 'enrolment' : 'enrolments');
        }
        if ($result['already'] !== []) {
            $parts[] = sprintf('Already here: %s.', $this->names($result['already']));
        }
        if ($parts !== []) {
            $this->session->flash('success', implode(' ', $parts));
        }
        if ($result['problems'] !== []) {
            $this->session->flash('error', implode(' ', $result['problems']));
        }

        return $this->back($group);
    }

    public function removeLearner(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        try {
            $this->service->removeLearner($tenant, $group, RequestContext::user($request), $request->routeInt('user'), $request->clientIp);
            $this->session->flash('success', 'Removed. Their courses and progress are kept.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return $this->back($group);
    }

    public function teachers(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $ids = array_values(array_filter(array_map('intval', array_values($request->inputArray('teachers'))), static fn (int $id): bool => $id > 0));
        try {
            $this->service->setTeachers($tenant, $group, RequestContext::user($request), $ids, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, [], 422);
        }
        $this->session->flash('success', $ids === [] ? 'No teachers now.' : 'Teachers saved.');

        return $this->back($group);
    }

    public function assignCourse(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $old = ['course_id' => $request->input('course_id', '') ?? '', 'due_on' => $request->input('due_on', '') ?? ''];
        try {
            $count = $this->service->assignCourse(
                $tenant,
                $group,
                RequestContext::user($request),
                ctype_digit($old['course_id']) ? (int) $old['course_id'] : 0,
                $old['due_on'],
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $old, 422);
        }
        $this->session->flash('success', $count > 0
            ? sprintf('Course set. %d %s enrolled.', $count, $count === 1 ? 'learner was' : 'learners were')
            : 'Course set.');

        return $this->back($group);
    }

    public function unassignCourse(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        try {
            $this->service->unassignCourse($tenant, $group, RequestContext::user($request), $request->routeInt('course'), $request->clientIp);
            $this->session->flash('success', 'Course removed from the list. Learners keep it and their progress.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return $this->back($group);
    }

    public function announce(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $body = $request->input('announcement', '') ?? '';
        try {
            $this->service->announce($tenant, $group, RequestContext::user($request), $body, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, ['announcement' => $body], 422);
        }
        $this->session->flash('success', 'Posted. Learners see it on their home page and in their notifications.');

        return $this->back($group);
    }

    public function deleteAnnouncement(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        try {
            $this->service->deleteAnnouncement($tenant, $group, RequestContext::user($request), $request->routeInt('announcement'), $request->clientIp);
            $this->session->flash('success', 'Announcement deleted.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return $this->back($group);
    }

    /** POST /groups/{group}/classes: plan a live class, once or weekly (ADR 0028). */
    public function scheduleClass(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        // The form's fields are prefixed so they never clash with the group page's other forms.
        $input = [];
        foreach (['title', 'date', 'time', 'minutes', 'mode', 'style', 'location', 'join_url', 'course_id', 'weeks', 'description'] as $key) {
            $input[$key] = (string) ($request->input('class_' . $key, '') ?? '');
        }
        if ($this->live === null) {
            throw new \LogicException('Live classes are not wired.');
        }
        try {
            $ids = $this->live->schedule($tenant, $group, RequestContext::user($request), $input, $request->clientIp);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors as $k => $m) {
                $errors[$k === 'form' ? 'form' : 'class_' . $k] = $m;
            }
            $old = ['class_open' => '1'];
            foreach ($input as $k => $value) {
                $old['class_' . $k] = $value;
            }

            return $this->render($request, $errors, $old, 422);
        }
        $this->session->flash('success', count($ids) === 1
            ? 'Class planned. Learners can see it and have been told.'
            : sprintf('%d weekly classes planned. Learners can see them and have been told.', count($ids)));

        return Response::redirect('/groups/' . $group->id . '#classes');
    }

    /** Attendance as a spreadsheet: one row per learner. */
    public function attendance(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        if ($this->classes === null) {
            throw HttpException::notFound();
        }
        $summary = $this->classes->groupSummary($tenant->id, $group->id, $this->clock->sqlNow());
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not build the export.');
        }
        fputcsv($out, ['Learner', 'Email', 'Classes held', 'Present', 'Late', 'Absent', 'Excused', 'Not marked', 'Attendance %'], ',', '"', '');
        foreach ($this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER) as $l) {
            $r = $summary['rows'][$l['user_id']] ?? ['held' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'unmarked' => 0, 'rate' => null];
            fputcsv($out, array_map(PaymentAdminController::cell(...), [
                $l['name'], $l['email'], (string) $r['held'], (string) $r['present'], (string) $r['late'], (string) $r['absent'], (string) $r['excused'], (string) $r['unmarked'],
                $r['rate'] === null ? '' : (string) $r['rate'],
            ]), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $file = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $group->name), '-');

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ($file === '' ? 'class' : $file) . '-attendance.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Progress as a spreadsheet, one row per learner and a column per course. */
    public function export(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $data = $this->data($tenant, $group);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not build the export.');
        }
        $header = ['Learner', 'Email'];
        foreach ($data['courses'] as $c) {
            $header[] = $c['course']->title;
        }
        $header[] = 'Last active';
        fputcsv($out, array_map(PaymentAdminController::cell(...), $header), ',', '"', '');
        foreach ($data['learners'] as $l) {
            $row = [$l['name'], $l['email']];
            foreach ($data['courses'] as $c) {
                $cell = $data['matrix']['cells'][$l['user_id']][$c['course']->id] ?? null;
                $total = $data['matrix']['lessons'][$c['course']->id] ?? 0;
                $row[] = $cell === null ? 'Not enrolled' : ($cell['status'] === 'completed' ? 'Completed' : $cell['done'] . ' of ' . $total);
            }
            $row[] = $data['matrix']['last_active'][$l['user_id']] ?? '';
            fputcsv($out, array_map(PaymentAdminController::cell(...), $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $file = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $group->name), '-');

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ($file === '' ? 'progress' : $file) . '-progress.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$tenant, $group] = $this->group($request);
        $user = RequestContext::user($request);
        $data = $this->data($tenant, $group);
        $all = $this->service->managesAll($user);
        $setIds = array_map(static fn (array $c): int => $c['course']->id, $data['courses']);
        $available = array_values(array_filter(
            $this->courses->listPublished($tenant->id, 100),
            static fn ($c): bool => !in_array($c->id, $setIds, true) && ($all || !$c->isPaid()),
        ));

        return Response::html($this->view->render('groups/show', [
            'pageTitle' => $group->name,
            'school' => $tenant,
            'group' => $group,
            'courses' => $data['courses'],
            'learners' => $data['learners'],
            'teachers' => $this->groups->members($tenant->id, $group->id, GroupRepository::TEACHER),
            'matrix' => $data['matrix'],
            'available' => $available,
            'unplaced' => $this->groups->learnersWithoutGroup($tenant->id),
            'staff' => $all ? $this->groups->staff($tenant->id) : [],
            'managesAll' => $all,
            'announcements' => $this->groups->announcements($tenant->id, $group->id),
            'today' => $this->clock->now()->setTimezone(new \DateTimeZone($tenant->timezone))->format('Y-m-d'),
            'liveClasses' => $this->classes === null ? null : [
                'upcoming' => $this->classes->forGroup($tenant->id, $group->id, $this->clock->sqlNow(), $this->clock->now()->modify('+90 days')->format('Y-m-d H:i:s'), 8),
                'past' => $this->classes->pastForGroup($tenant->id, $group->id, $this->clock->sqlNow(), 6),
                'attendance' => $this->classes->groupSummary($tenant->id, $group->id, $this->clock->sqlNow()),
                'mayTeach' => $this->service->mayTeach($tenant, $group, $user),
                'now' => $this->clock->now()->getTimestamp(),
                'courses' => $this->courses->listPublished($tenant->id, 100),
                'classroomReady' => $this->live?->classroomReady($tenant) ?? false,
            ],
            'exams' => $this->exams($tenant, $group, $data['learners']),
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * The class's CBT exams and each learner's latest result (ADR 0045). Teachers see every score,
     * whatever the exam shows candidates.
     *
     * @param list<array{user_id: int, name: string, email: string, added_at: string}> $learners
     * @return ?array{list: list<\App\Modules\Exams\Cbt\CbtExam>, cells: array<int, array<int, array{percent: ?int, total: ?int, max: ?int, done: bool, away: int}>>}
     */
    private function exams(Tenant $tenant, LearnerGroup $group, array $learners): ?array
    {
        if ($this->cbt === null) {
            return null;
        }
        $list = $this->cbt->forGroup($tenant->id, $group->id, 6);

        return [
            'list' => $list,
            'cells' => $this->cbt->latestFor($tenant->id, array_map(static fn ($e): int => $e->id, $list), array_map(static fn (array $l): int => $l['user_id'], $learners)),
        ];
    }

    /** Exam results as a spreadsheet: one row per learner, a column per exam (total, out of, percent). */
    public function examsExport(Request $request): Response
    {
        [$tenant, $group] = $this->group($request);
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);
        $exams = $this->exams($tenant, $group, $learners) ?? ['list' => [], 'cells' => []];
        $header = ['Learner', 'Email'];
        foreach ($exams['list'] as $e) {
            $header[] = $e->title . ' (total)';
            $header[] = $e->title . ' (out of)';
            $header[] = $e->title . ' (% right)';
        }
        $rows = [];
        foreach ($learners as $l) {
            $row = [$l['name'], $l['email']];
            foreach ($exams['list'] as $e) {
                $cell = $exams['cells'][$l['user_id']][$e->id] ?? null;
                $done = $cell !== null && $cell['done'];
                $row[] = $done ? $cell['total'] : ($cell === null ? 'not sat' : 'sitting');
                $row[] = $done ? $cell['max'] : null;
                $row[] = $done ? $cell['percent'] : null;
            }
            $rows[] = $row;
        }
        $file = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $group->name), '-');

        return new Response(\App\Modules\Reports\Csv::build($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ($file === '' ? 'class' : $file) . '-exams.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{courses: list<array{course: \App\Modules\Courses\Course, due_on: ?string}>, learners: list<array{user_id: int, name: string, email: string, added_at: string}>,
     *               matrix: array{lessons: array<int, int>, cells: array<int, array<int, array{status: string, done: int}>>, last_active: array<int, string>}}
     */
    private function data(Tenant $tenant, LearnerGroup $group): array
    {
        $courses = $this->groups->courses($tenant->id, $group->id);
        $learners = $this->groups->members($tenant->id, $group->id, GroupRepository::LEARNER);

        return [
            'courses' => $courses,
            'learners' => $learners,
            'matrix' => $this->progress->matrix(
                $tenant->id,
                array_map(static fn (array $l): int => $l['user_id'], $learners),
                array_map(static fn (array $c): int => $c['course']->id, $courses),
            ),
        ];
    }

    /** @return array{Tenant, LearnerGroup} */
    private function group(Request $request): array
    {
        $tenant = RequestContext::tenant($request);

        return [$tenant, $this->service->groupFor($tenant, $request->routeInt('group'), RequestContext::user($request))];
    }

    /** @return array{name: string, level: string, period: string} */
    private function details(Request $request): array
    {
        return [
            'name' => $request->input('name', '') ?? '',
            'level' => $request->input('level', '') ?? '',
            'period' => $request->input('period', '') ?? '',
        ];
    }

    private function back(LearnerGroup $group): Response
    {
        return Response::redirect('/groups/' . $group->id);
    }

    /** @param list<string> $names */
    private function names(array $names): string
    {
        if (count($names) > 5) {
            return sprintf('%s and %d others', implode(', ', array_slice($names, 0, 4)), count($names) - 4);
        }
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }
}
