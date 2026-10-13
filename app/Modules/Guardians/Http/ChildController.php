<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Certificates\CertificateService;
use App\Modules\Groups\GroupRepository;
use App\Modules\Guardians\ChildReport;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Guardians\GuardianService;

/** A guardian's read-only view of the learners linked to them (PAR-002). */
final class ChildController
{
    public function __construct(
        private readonly View $view,
        private readonly GuardianService $service,
        private readonly GuardianRepository $links,
        private readonly ChildReport $report,
        private readonly GroupRepository $groups,
        private readonly CertificateService $certificates,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $children = $this->links->childrenOf($tenant->id, RequestContext::user($request)->user->id);
        if ($children === []) {
            throw HttpException::notFound();
        }
        if (count($children) === 1) {
            return Response::redirect('/children/' . $children[0]->learnerId);
        }

        return Response::html($this->view->render('guardians/children', ['pageTitle' => 'Your children', 'children' => $children]));
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $viewer = RequestContext::user($request);
        $link = $this->service->viewLink($tenant, $viewer, $request->routeInt('learner'));
        $groups = $link->canProgress || $link->canNotices ? $this->groups->forLearner($tenant->id, $link->learnerId) : [];

        return Response::html($this->view->render('guardians/child', [
            'pageTitle' => $link->learnerName,
            'school' => $tenant,
            'link' => $link,
            'siblings' => $this->links->childrenOf($tenant->id, $viewer->user->id),
            'courses' => $link->canProgress ? $this->report->courses($tenant->id, $link->learnerId) : [],
            'results' => $link->canResults ? $this->report->results($tenant->id, $link->learnerId) : null,
            'certificates' => $link->canResults || $link->canProgress ? $this->certificates->forUser($tenant->id, $link->learnerId) : [],
            'groups' => $groups,
            'today' => (new \DateTimeImmutable('now', new \DateTimeZone($tenant->timezone)))->format('Y-m-d'),
        ]));
    }
}
