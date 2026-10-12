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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $before = $request->query('before');
        $beforeId = $before !== null && ctype_digit($before) ? (int) $before : null;

        $events = $this->audit->page($tenant->id, $beforeId, self::PER_PAGE + 1);
        $hasMore = count($events) > self::PER_PAGE;
        $events = array_slice($events, 0, self::PER_PAGE);

        $rows = array_map(fn (array $e): array => $this->describer->describe($e) + ['at' => $e['created_at']], $events);

        return Response::html($this->view->render('activity/index', [
            'pageTitle' => 'Activity',
            'rows' => $rows,
            'nextBefore' => $hasMore && $events !== [] ? $events[count($events) - 1]['id'] : null,
            'isFirstPage' => $beforeId === null,
        ]));
    }
}
