<?php

declare(strict_types=1);

namespace App\Modules\Api\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Api\ApiKeyRepository;
use App\Modules\Api\ApiKeys;

/** School → Settings → API keys (ADR 0036). */
final class ApiKeyController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly ApiKeys $keys,
        private readonly ApiKeyRepository $repository,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function store(Request $request): Response
    {
        $name = (string) ($request->input('name', '') ?? '');
        try {
            $key = $this->keys->create(RequestContext::tenant($request), RequestContext::user($request), $name, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $name, 422);
        }
        // Shown once, on the next page; only its hash is stored.
        $this->session->flash('api_key', $key);

        return Response::redirect('/school/api');
    }

    public function revoke(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $key = $this->repository->find($tenant->id, $request->routeInt('key')) ?? throw HttpException::notFound();
        $this->keys->revoke($tenant, RequestContext::user($request), $key, $request->clientIp);
        $this->session->flash('success', 'Revoked “' . $key->name . '”. Anything using it can no longer read your data.');

        return Response::redirect('/school/api');
    }

    /** @param array<string, string> $errors */
    private function render(Request $request, array $errors = [], string $name = '', int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);

        return Response::html($this->view->render('school/api', [
            'pageTitle' => 'API keys',
            'keys' => $this->repository->all($tenant->id),
            'newKey' => $this->session->pullFlash('api_key'),
            'baseUrl' => RequestContext::baseUrl($request),
            'errors' => $errors,
            'name' => $name,
        ]), $status);
    }
}
