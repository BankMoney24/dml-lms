<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Audit\ActivityDescriber;
use App\Modules\Audit\AuditLog;

/** Read-only audit trail for owners and admins (ADM-002). */
final class ActivityController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly AuditLog $audit,
        private readonly ActivityDescriber $describer,
        private readonly ?\App\Core\Clock $clock = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $before = $request->query('before');
        $beforeId = $before !== null && ctype_digit($before) ? (int) $before : null;

        $kind = (string) ($request->query('kind') ?? '');
        $kind = isset(ActivityDescriber::KINDS[$kind]) ? $kind : '';
        $who = mb_substr(trim((string) ($request->query('who') ?? '')), 0, 80);

        $events = $this->audit->page($tenant->id, $beforeId, self::PER_PAGE + 1, $kind === '' ? [] : ActivityDescriber::KINDS[$kind][1], $who);
        $hasMore = count($events) > self::PER_PAGE;
        $events = array_slice($events, 0, self::PER_PAGE);

        $rows = array_map(fn (array $e): array => $this->describer->describe($e) + ['at' => $e['created_at']], $events);

        return Response::html($this->view->render('activity/index', [
            'pageTitle' => 'Activity',
            'rows' => $rows,
            'nextBefore' => $hasMore && $events !== [] ? $events[count($events) - 1]['id'] : null,
            'isFirstPage' => $beforeId === null,
            'kind' => $kind,
            'who' => $who,
            'today' => $today = ($this->clock?->now() ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone($tenant->timezone ?: 'UTC')),
            'todayKey' => $today->format('Y-m-d'),
            'yesterdayKey' => $today->modify('-1 day')->format('Y-m-d'),
        ]));
    }
}
