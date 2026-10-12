<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;

/** Operations overview for platform administrators. Everyone else gets "not found". */
final class SystemController
{
    public function __construct(private readonly View $view, private readonly SystemStatus $status)
    {
    }

    public function show(Request $request): Response
    {
        if (!RequestContext::user($request)->user->isPlatformAdmin) {
            throw HttpException::notFound();
        }
        $report = $this->status->report();
        $states = array_column(array_filter($report, 'is_array'), 'state');

        return Response::html($this->view->render('platform/system', [
            'pageTitle' => 'System',
            'report' => $report,
            'overall' => in_array('bad', $states, true) ? 'bad' : (in_array('warn', $states, true) ? 'warn' : 'ok'),
        ]));
    }
}
