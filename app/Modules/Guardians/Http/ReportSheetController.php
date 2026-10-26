<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Guardians\GuardianService;
use App\Modules\Guardians\ReportSheet;

/**
 * Printable learner reports (ADR 0032): for a parent at /children/{learner}/report
 * (what their link allows) and for staff who manage people at /people/{user}/report.
 */
final class ReportSheetController
{
    public function __construct(
        private readonly View $view,
        private readonly GuardianService $guardians,
        private readonly ReportSheet $sheet,
    ) {
    }

    public function forParent(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $link = $this->guardians->viewLink($tenant, RequestContext::user($request), $request->routeInt('learner'));

        return $this->render($request, $link->learnerId, $link->learnerName, $link->canProgress, $link->canResults, '/children/' . $link->learnerId, false);
    }

    public function forStaff(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $learner = $this->guardians->learnerOf($tenant, $request->routeInt('user'));

        return $this->render($request, $learner['id'], $learner['name'], true, true, '/people/' . $learner['id'] . '/report', true);
    }

    private function render(Request $request, int $learnerId, string $name, bool $progress, bool $results, string $back, bool $staff): Response
    {
        $tenant = RequestContext::tenant($request);
        $period = $this->sheet->period($tenant, $request->query('from'), $request->query('to'));

        return Response::html($this->view->render('guardians/report', [
            'pageTitle' => 'Report: ' . $name,
            'robots' => 'noindex',
            'school' => $tenant,
            'learnerName' => $name,
            'period' => $period,
            'data' => $this->sheet->build($tenant->id, $learnerId, $period, $progress, $results),
            'back' => $back,
            'staff' => $staff,
            'here' => $staff ? '/people/' . $learnerId . '/report' : '/children/' . $learnerId . '/report',
            'issued' => (new \DateTimeImmutable('now', new \DateTimeZone($tenant->timezone !== '' ? $tenant->timezone : 'UTC')))->format('j F Y'),
        ]));
    }
}
