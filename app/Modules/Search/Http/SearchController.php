<?php

declare(strict_types=1);

namespace App\Modules\Search\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Search\SearchService;

/** "/search": a results page, and the same results as JSON for the search box in the app bar. */
final class SearchController
{
    public function __construct(private readonly View $view, private readonly SearchService $search)
    {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $q = SearchService::normalise($request->query('q', '') ?? '');
        $groups = $this->search->search($tenant, $user, $q, $request->query('format') === 'json' ? 5 : 20);

        if ($request->query('format') === 'json') {
            return Response::json(['q' => $q, 'groups' => $groups])
                ->withHeader('Cache-Control', 'private, no-store');
        }
        $this->view->share('search_q', $q);

        return Response::html($this->view->render('search/index', [
            'pageTitle' => $q === '' ? 'Search' : 'Search: ' . $q,
            'q' => $q,
            'groups' => $groups,
            'tooShort' => $q !== '' && mb_strlen($q) < SearchService::MIN_LENGTH,
        ]));
    }
}
