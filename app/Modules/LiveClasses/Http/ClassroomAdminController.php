<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\LiveClasses\Classroom\Classroom;

/** /school/classroom: connecting the school's own Jitsi server (ADR 0029). The secret is write-only. */
final class ClassroomAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Classroom $classroom,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function save(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $input = [
            'domain' => (string) ($request->input('domain', '') ?? ''),
            'app_id' => (string) ($request->input('app_id', '') ?? ''),
            'secret' => (string) ($request->input('secret', '') ?? ''),
            'low_data' => $request->input('low_data', '') === '1',
        ];
        $first = $this->classroom->settingsFor($tenant) === null;
        try {
            $this->classroom->configure($tenant, RequestContext::user($request), $input, $request->clientIp);
        } catch (ValidationException $e) {
            // Never echo the secret back into the page.
            return $this->render($request, $e->errors, ['domain' => $input['domain'], 'app_id' => $input['app_id'], 'low_data' => $input['low_data'] ? '1' : '0'], 422);
        }
        $this->session->flash('success', $first
            ? 'Classroom connected. Teachers can now choose “Online classroom on this site” when they plan a class.'
            : 'Classroom settings saved.');

        return Response::redirect('/school/classroom');
    }

    /** POST /school/classroom/check: does the server answer? */
    public function check(Request $request): Response
    {
        $result = $this->classroom->check(RequestContext::tenant($request));
        $this->session->flash($result['ok'] ? 'success' : 'error', $result['detail']);

        return Response::redirect('/school/classroom');
    }

    public function disconnect(Request $request): Response
    {
        $this->classroom->disconnect(RequestContext::tenant($request), RequestContext::user($request), $request->clientIp);
        $this->session->flash('success', 'Classroom disconnected. Classes already planned in it cannot be joined until it is connected again.');

        return Response::redirect('/school/classroom');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $settings = $this->classroom->settingsFor(RequestContext::tenant($request));

        return Response::html($this->view->render('school/classroom', [
            'pageTitle' => 'Online classroom',
            'settings' => $settings,
            'errors' => $errors,
            'old' => $old + [
                'domain' => $settings->domain ?? '',
                'app_id' => $settings->appId ?? '',
                'low_data' => $settings === null || $settings->lowData ? '1' : '0',
            ],
        ]), $status);
    }
}
