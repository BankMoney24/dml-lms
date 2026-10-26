<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\LiveClasses\Http\RoomHeaders;
use App\Modules\Meetings\Meeting;
use App\Modules\Meetings\MeetingRepository;
use App\Modules\Meetings\Meetings;

/** /meetings: virtual meetings in the online classroom (ADR 0030). */
final class MeetingController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Meetings $service,
        private readonly MeetingRepository $meetings,
        private readonly Clock $clock,
    ) {
    }

    /** GET /meetings */
    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $now = $this->clock->now();

        return Response::html($this->view->render('meetings/index', [
            'pageTitle' => 'Meetings',
            'upcoming' => $this->meetings->forUser($tenant->id, $user->user->id, $now->format('Y-m-d H:i:s'), $now->modify('+90 days')->format('Y-m-d H:i:s')),
            'past' => $this->meetings->pastForUser($tenant->id, $user->user->id, $now->format('Y-m-d H:i:s'), 10),
            'mayHost' => Meetings::mayHost($user),
            'ready' => $this->service->ready($tenant),
            'userId' => $user->user->id,
            'now' => $now->getTimestamp(),
        ]));
    }

    /** GET /meetings/new */
    public function create(Request $request): Response
    {
        return $this->renderForm($request);
    }

    /** POST /meetings */
    public function store(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        if (!Meetings::mayHost($user)) {
            throw HttpException::notFound();
        }
        $input = [
            'title' => (string) ($request->input('title', '') ?? ''),
            'agenda' => (string) ($request->input('agenda', '') ?? ''),
            'when' => (string) ($request->input('when', 'now') ?? 'now'),
            'date' => (string) ($request->input('date', '') ?? ''),
            'time' => (string) ($request->input('time', '') ?? ''),
            'minutes' => (string) ($request->input('minutes', '60') ?? '60'),
            'staff_moderate' => $request->input('staff_moderate', '') === '1' ? '1' : '',
            'staff' => $request->input('staff', '') === '1' ? '1' : '',
            'groups' => array_values($request->inputArray('groups')),
            'parents' => array_values($request->inputArray('parents')),
            'emails' => (string) ($request->input('emails', '') ?? ''),
        ];
        try {
            $meeting = $this->service->create($tenant, $user, $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderForm($request, $e->errors, $input, 422);
        }
        if ($input['when'] !== 'later') {
            return Response::redirect('/meetings/' . $meeting->id . '/room');
        }
        $this->session->flash('success', sprintf('Meeting planned. %d %s been invited and told.', $meeting->invited, $meeting->invited === 1 ? 'person has' : 'people have'));

        return Response::redirect('/meetings/' . $meeting->id);
    }

    /** GET /meetings/{meeting} */
    public function show(Request $request): Response
    {
        return $this->renderShow($request);
    }

    /** POST /meetings/{meeting}/invite */
    public function invite(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        $input = [
            'staff' => $request->input('staff', '') === '1' ? '1' : '',
            'groups' => array_values($request->inputArray('groups')),
            'parents' => array_values($request->inputArray('parents')),
            'emails' => (string) ($request->input('emails', '') ?? ''),
        ];
        try {
            $n = $this->service->inviteMore($tenant, $meeting, $user, $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderShow($request, $e->errors, ['emails' => $input['emails'], 'invite_open' => '1'], 422);
        }
        $this->session->flash('success', $n === 0 ? 'Everyone you chose was already invited.' : sprintf('%d more %s invited and told.', $n, $n === 1 ? 'person' : 'people'));

        return Response::redirect('/meetings/' . $meeting->id . '#people');
    }

    /** POST /meetings/{meeting}/cancel */
    public function cancel(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        $reason = (string) ($request->input('reason', '') ?? '');
        try {
            $this->service->cancel($tenant, $meeting, $user, $reason, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->renderShow($request, $e->errors, ['reason' => $reason, 'cancel_open' => '1'], 422);
        }
        $this->session->flash('success', 'Meeting cancelled. Everyone invited has been told.');

        return Response::redirect('/meetings/' . $meeting->id);
    }

    /** POST /meetings/{meeting}/extend: JSON for the room page, a redirect otherwise. */
    public function extend(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        $wantsJson = str_contains((string) $request->header('accept'), 'application/json');
        try {
            $end = $this->service->extend($meeting, $user, (int) ($request->input('minutes', '30') ?? '30'));
        } catch (ValidationException $e) {
            if ($wantsJson) {
                return Response::json(['ok' => false, 'error' => implode(' ', $e->errors)], 409);
            }
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/meetings/' . $meeting->id);
        }
        if ($wantsJson) {
            return Response::json([
                'ok' => true,
                'ends' => (int) strtotime($end . ' UTC'),
                'ends_label' => (new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tenant->timezone))->format('H:i'),
            ]);
        }
        $this->session->flash('success', 'Meeting made longer.');

        return Response::redirect('/meetings/' . $meeting->id);
    }

    /** POST /meetings/{meeting}/end */
    public function finish(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        $this->service->finish($meeting, $user, $request->clientIp);
        $this->session->flash('success', 'Meeting ended. Who came, and for how long, is below.');

        return Response::redirect('/meetings/' . $meeting->id . '#people');
    }

    /** GET /meetings/{meeting}/room */
    public function room(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        $runs = $this->service->runsRoom($tenant, $meeting, $user);
        $access = $this->service->access($tenant, $meeting, $runs);
        $pass = $access['ok'] ? $this->service->pass($tenant, $meeting, $user) : null;
        $response = Response::html($this->view->render('meetings/room', [
            'pageTitle' => $meeting->title . ': meeting',
            'meeting' => $meeting,
            'isHost' => $this->service->isHost($meeting, $user),
            'runs' => $runs,
            'access' => $access,
            'pass' => $pass,
            'now' => $this->clock->now()->getTimestamp(),
        ]), $access['ok'] ? 200 : 409)->withHeader('Cache-Control', 'private, no-store');

        return $pass === null ? $response : RoomHeaders::apply($response, $pass['domain']);
    }

    /** POST /meetings/{meeting}/signal */
    public function signal(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        try {
            $this->service->signal($tenant, $meeting, $user, (string) ($request->input('type', '') ?? ''));
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'error' => implode(' ', $e->errors)], 409);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    private function renderForm(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        if (!Meetings::mayHost($user)) {
            throw HttpException::notFound();
        }
        $local = $this->clock->now()->setTimezone(new \DateTimeZone($tenant->timezone))->modify('+1 hour');

        return Response::html($this->view->render('meetings/new', [
            'pageTitle' => 'New meeting',
            'groups' => $this->service->invitableGroups($tenant, $user),
            'ready' => $this->service->ready($tenant),
            'errors' => $errors,
            'old' => $old + [
                'title' => '', 'agenda' => '', 'when' => 'now', 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:00'), 'minutes' => '60',
                'staff_moderate' => '1', 'staff' => '', 'groups' => [], 'parents' => [], 'emails' => '',
            ],
            'minutes' => Meeting::MINUTES,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderShow(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $meeting = $this->service->meetingFor($tenant, $request->routeInt('meeting'), $user);
        $isHost = $this->service->isHost($meeting, $user);
        $now = $this->clock->now()->getTimestamp();

        return Response::html($this->view->render('meetings/show', [
            'pageTitle' => $meeting->title,
            'meeting' => $meeting,
            'isHost' => $isHost,
            'runs' => $this->service->runsRoom($tenant, $meeting, $user),
            'invitees' => $isHost ? $this->meetings->invitees($tenant->id, $meeting->id) : [],
            'minutesIn' => $isHost ? $this->meetings->minutes($tenant->id, $meeting) : [],
            'groups' => $isHost ? $this->service->invitableGroups($tenant, $user) : [],
            'now' => $now,
            'errors' => $errors,
            'old' => $old + ['emails' => '', 'reason' => ''],
        ]), $status);
    }
}
