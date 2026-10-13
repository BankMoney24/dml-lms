<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Notifications\NotificationRepository;
use App\Modules\Notifications\Notifier;

/** The notification centre: your own notifications only, in this school only. */
final class NotificationController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $this->notifications->pruneRead($tenant->id, $user->user->id);

        return Response::html($this->view->render('notifications/index', [
            'pageTitle' => 'Notifications',
            'items' => $this->notifications->recent($tenant->id, $user->user->id),
        ]));
    }

    /** Following a notification marks it read, then goes where it points (always a path on this site). */
    public function open(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $item = $this->notifications->find($tenant->id, $user->user->id, $request->routeInt('notification'));
        if ($item === null) {
            throw HttpException::notFound();
        }
        $this->notifications->markRead($tenant->id, $user->user->id, $item->id);

        return Response::redirect(Notifier::safeLink($item->link) ?? '/notifications');
    }

    public function readAll(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $count = $this->notifications->markAllRead($tenant->id, $user->user->id);
        $this->session->flash('success', $count === 0 ? 'Nothing new.' : 'All marked as read.');

        return Response::redirect('/notifications');
    }
}
