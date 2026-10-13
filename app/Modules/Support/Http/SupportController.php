<?php

declare(strict_types=1);

namespace App\Modules\Support\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Files\FileRepository;
use App\Modules\Support\SupportRepository;
use App\Modules\Support\SupportRequest;
use App\Modules\Support\SupportService;

/** "Get help" for everyone, and the school's help desk for support staff. */
final class SupportController
{
    private const FILTERS = ['open' => SupportRequest::OPEN, 'answered' => SupportRequest::ANSWERED, 'resolved' => SupportRequest::RESOLVED];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly SupportService $service,
        private readonly SupportRepository $requests,
        private readonly FileRepository $files,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    public function store(Request $request): Response
    {
        $old = [
            'category' => $request->input('category', '') ?? '',
            'subject' => $request->input('subject', '') ?? '',
            'body' => $request->input('body', '') ?? '',
        ];
        try {
            $id = $this->service->open(
                RequestContext::tenant($request),
                RequestContext::user($request),
                $old['category'],
                $old['subject'],
                $old['body'],
                $request->files('attachments'),
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors, $old, 422);
        }
        $this->session->flash('success', 'Sent. The school will answer here, and you will get a notification.');

        return Response::redirect('/help/' . $id);
    }

    public function show(Request $request): Response
    {
        return $this->renderThread($request);
    }

    public function reply(Request $request): Response
    {
        $id = $request->routeInt('request');
        $body = $request->input('body', '') ?? '';
        try {
            $this->service->reply(RequestContext::tenant($request), RequestContext::user($request), $id, $body, $request->files('attachments'), $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderThread($request, $e->errors, ['body' => $body], 422);
        }
        $this->session->flash('success', 'Reply sent.');

        return Response::redirect('/help/' . $id);
    }

    public function status(Request $request): Response
    {
        $id = $request->routeInt('request');
        $resolved = $request->input('resolved') === '1';
        $this->service->setResolved(RequestContext::tenant($request), RequestContext::user($request), $id, $resolved, $request->clientIp);
        $this->session->flash('success', $resolved ? 'Marked resolved.' : 'Reopened.');

        return Response::redirect('/help/' . $id);
    }

    /** The help desk: requests by status, oldest waiting first. */
    public function inbox(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        if (!$this->service->isStaff(RequestContext::user($request))) {
            throw HttpException::forbidden();
        }
        $filter = (string) ($request->query('status') ?? 'open');
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'open';

        return Response::html($this->view->render('support/inbox', [
            'pageTitle' => 'Help desk',
            'requests' => $this->requests->queue($tenant->id, self::FILTERS[$filter]),
            'counts' => $this->requests->counts($tenant->id),
            'filter' => $filter,
        ]));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderIndex(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);

        return Response::html($this->view->render('support/index', [
            'pageTitle' => 'Get help',
            'school' => $tenant,
            'mine' => $this->requests->forUser($tenant->id, $user->user->id),
            'isStaff' => $this->service->isStaff($user),
            'categories' => SupportRequest::CATEGORIES,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderThread(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $help = $this->service->requestFor($tenant, $user, $request->routeInt('request'));
        $messages = $this->requests->messages($tenant->id, $help->id);

        return Response::html($this->view->render('support/thread', [
            'pageTitle' => $help->subject,
            'help' => $help,
            'messages' => $messages,
            'files' => $this->files->forSupportMessages($tenant->id, array_column($messages, 'id')),
            'isStaff' => $this->service->isStaff($user) && $help->userId !== $user->user->id,
            'mine' => $help->userId === $user->user->id,
            'errors' => $errors,
            'old' => $old,
        ]), $status);
    }
}
