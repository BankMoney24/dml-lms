<?php

declare(strict_types=1);

namespace App\Modules\Achievements\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Achievements\Achievements;

/** A learner's achievements, earned and still to earn (ADR 0023). */
final class AchievementsController
{
    public function __construct(
        private readonly View $view,
        private readonly Achievements $achievements,
        private readonly ?\App\Modules\Study\ActivityReport $activity = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = RequestContext::user($request);
        $streak = $this->activity?->for($tenant->id, $me->user->id, $tenant->timezone)['best_streak'] ?? null;
        $this->achievements->refresh($tenant->id, $me->user->id, $streak);

        return Response::html($this->view->render('achievements/index', [
            'pageTitle' => 'Achievements',
            'earned' => $this->achievements->earned($tenant->id, $me->user->id),
            'badges' => Achievements::BADGES,
        ]));
    }
}
