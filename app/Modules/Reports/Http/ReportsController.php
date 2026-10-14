<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Permission;
use App\Modules\Reports\Csv;
use App\Modules\Reports\SchoolReport;

/**
 * School → Reports (ADR 0022). Course figures for everyone who may see reports;
 * the learner list with email addresses only for people who manage members;
 * money only for people who manage the school. Downloads are recorded.
 */
final class ReportsController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly SchoolReport $report,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $me->authorise(Permission::ReportsView);
        $seesPeople = $me->can(Permission::MembersManage);
        $find = mb_substr(trim((string) ($request->query('find', '') ?? '')), 0, 80);
        $group = (int) ($request->query('group', '0') ?? '0');
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $learners = $seesPeople ? $this->report->learners($tenant->id, $find, $group > 0 ? $group : null, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE) : [];

        return Response::html($this->view->render('school/reports', [
            'pageTitle' => 'Reports',
            'schoolTab' => 'reports',
            'totals' => $this->report->totals($tenant->id),
            'revenue' => $me->can(Permission::SchoolManage) ? $this->report->revenue($tenant->id) : null,
            'courses' => $this->report->courses($tenant->id),
            'seesPeople' => $seesPeople,
            'learners' => array_slice($learners, 0, self::PER_PAGE),
            'hasMore' => count($learners) > self::PER_PAGE,
            'page' => $page,
            'find' => $find,
            'group' => $group,
            'groups' => $seesPeople ? $this->report->groups($tenant->id) : [],
        ]));
    }

    public function coursesCsv(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $me->authorise(Permission::ReportsView);
        $rows = array_map(static fn (array $c): array => [
            $c['title'], $c['status'] === 'published' ? 'Published' : 'Draft', $c['lessons'], $c['enrolled'], $c['not_started'], $c['in_progress'],
            $c['completed'], $c['completion_rate'], $c['avg_done'], $c['last_activity'],
        ], $this->report->courses($tenant->id));
        $this->audit->record($tenant->id, $me->user->id, 'reports.exported', 'tenant', $tenant->id, ['report' => 'courses', 'rows' => count($rows)], $request->clientIp);

        return $this->csv($tenant->slug . '-courses', Csv::build(
            ['Course', 'Status', 'Lessons', 'Enrolled', 'Not started', 'In progress', 'Completed', 'Completion rate (%)', 'Average progress (%)', 'Last activity (UTC)'],
            $rows,
        ));
    }

    public function learnersCsv(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $me->authorise(Permission::MembersManage);
        $group = (int) ($request->query('group', '0') ?? '0');
        $learners = $this->report->learners($tenant->id, (string) ($request->query('find', '') ?? ''), $group > 0 ? $group : null, SchoolReport::MAX_EXPORT);
        $rows = array_map(static fn (array $l): array => [
            $l['name'], $l['email'], substr($l['joined'], 0, 10), $l['courses'], $l['completed'], $l['lessons_done'], $l['certificates'], $l['last_activity'],
        ], $learners);
        $this->audit->record($tenant->id, $me->user->id, 'reports.exported', 'tenant', $tenant->id, ['report' => 'learners', 'rows' => count($rows)], $request->clientIp);

        return $this->csv($tenant->slug . '-learners', Csv::build(
            ['Name', 'Email', 'Joined', 'Courses', 'Courses completed', 'Lessons done', 'Certificates', 'Last activity (UTC)'],
            $rows,
        ));
    }

    private function csv(string $name, string $body): Response
    {
        $file = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) . '-' . $this->clock->now()->format('Y-m-d') . '.csv';

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
