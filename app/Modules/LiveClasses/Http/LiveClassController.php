<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Http;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\CourseRepository;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\Permission;
use App\Modules\LiveClasses\ClassSession;
use App\Modules\LiveClasses\ClassSessionRepository;
use App\Modules\LiveClasses\LiveClasses;

/** /classes: what is on, joining, the register, and changing or cancelling a class (ADR 0028). */
final class LiveClassController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly LiveClasses $live,
        private readonly ClassSessionRepository $sessions,
        private readonly GroupRepository $groups,
        private readonly CourseRepository $courses,
        private readonly Clock $clock,
        private readonly ?\App\Modules\LiveClasses\ClassMaterials $materials = null,
        private readonly ?\App\Modules\LiveClasses\Classroom\Classroom $classroom = null,
        private readonly ?\App\Modules\Files\FileService $fileService = null,
        private readonly ?\App\Modules\Meetings\MeetingRepository $meetingRepo = null,
    ) {
    }

    /**
     * GET /classes/{session}/room: the online classroom (ADR 0029). The pass
     * into the room is made here, after the server-side checks, and is only
     * ever sent to this one person's page.
     */
    public function room(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $group = $this->groups->find($tenant->id, $session->groupId);
        $teaches = $group !== null && $this->live->mayTeach($tenant, $group, $user);
        if ($this->classroom === null || !$session->inClassroom()) {
            return Response::redirect('/classes/' . $session->id);
        }
        $access = $this->classroom->access($tenant, $session, $teaches);
        $pass = $access['ok'] ? $this->classroom->pass($tenant, $session, $user, $teaches) : null;
        $materials = $this->materials?->list($tenant, $session) ?? [];
        $response = Response::html($this->view->render('classes/room', [
            'pageTitle' => $session->title . ': classroom',
            'session' => $session,
            'teaches' => $teaches,
            'access' => $access,
            'pass' => $pass,
            'materials' => array_values(array_filter($materials, static fn ($f): bool => $f->id !== $session->recordingFileId)),
            'now' => $this->clock->now()->getTimestamp(),
        ]), $access['ok'] ? 200 : 409)->withHeader('Cache-Control', 'private, no-store');
        return $pass === null ? $response : RoomHeaders::apply($response, $pass['domain']);
    }

    /** POST /classes/{session}/extend: more time while it runs (JSON for the room page). */
    public function extend(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $wantsJson = str_contains((string) $request->header('accept'), 'application/json');
        try {
            $end = $this->live->extend($tenant, $session, $user, (int) ($request->input('minutes', '30') ?? '30'));
        } catch (ValidationException $e) {
            if ($wantsJson) {
                return Response::json(['ok' => false, 'error' => implode(' ', $e->errors)], 409);
            }
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/classes/' . $session->id);
        }
        if ($wantsJson) {
            return Response::json([
                'ok' => true,
                'ends' => (int) strtotime($end . ' UTC'),
                'ends_label' => (new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tenant->timezone))->format('H:i'),
            ]);
        }
        $this->session->flash('success', 'Class made longer.');

        return Response::redirect('/classes/' . $session->id);
    }

    /** POST /classes/{session}/end: finish now; the room closes and the register is ready. */
    public function finish(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $this->live->finish($tenant, $session, $user, $request->clientIp);
        $this->session->flash('success', 'Class ended. Take the register below; learners who joined online are already marked.');

        return Response::redirect('/classes/' . $session->id . '#register');
    }

    /** POST /classes/now: start a class straight away for one of the teacher's classes (ADR 0030). */
    public function startNow(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $group = $this->groups->find($tenant->id, (int) ($request->input('group_id', '0') ?? '0'));
        if ($group === null || !$this->live->mayTeach($tenant, $group, $user)) {
            $this->session->flash('error', 'Choose one of the classes you teach.');

            return Response::redirect('/classes');
        }
        try {
            $session = $this->live->startNow(
                $tenant,
                $group,
                $user,
                (string) ($request->input('title', '') ?? ''),
                (int) ($request->input('minutes', '60') ?? '60'),
                (string) ($request->input('style', 'lecture') ?? 'lecture'),
                $request->clientIp,
            );
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect((string) ($request->input('back', '') ?? '') === 'group' ? '/groups/' . $group->id . '#classes' : '/classes');
        }

        return Response::redirect('/classes/' . $session->id . '/room');
    }

    /** POST /classes/{session}/signal: join, still here, leave (from the classroom page; also sent with sendBeacon). */
    public function signal(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $group = $this->groups->find($tenant->id, $session->groupId);
        $teaches = $group !== null && $this->live->mayTeach($tenant, $group, $user);
        if ($this->classroom === null) {
            throw \App\Core\Http\HttpException::notFound();
        }
        try {
            $this->classroom->signal($tenant, $session, $user, (string) ($request->input('type', '') ?? ''), $teaches);
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'error' => implode(' ', $e->errors)], 409);
        }
        $mark = $teaches ? null : $this->sessions->markFor($tenant->id, $session->id, $user->user->id);

        return Response::json(['ok' => true, 'status' => $mark['status'] ?? null]);
    }

    /** POST /classes/{session}/materials */
    public function addMaterial(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $upload = $request->file('material');
        $asRecording = $request->input('recording', '') === '1';
        try {
            if ($upload === null || $this->materials === null) {
                throw ValidationException::single('material', 'Choose a file first.');
            }
            $file = $this->materials->add($tenant, $session, $user, $upload, $asRecording, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, ['recording' => $asRecording ? '1' : ''], 422);
        }
        $message = $asRecording ? '“%s” is the class recording now (%s). Learners in the class can watch it.' : '“%s” added (%s).';
        $this->session->flash('success', sprintf($message, $file->originalName, $file->humanSize()));

        return Response::redirect('/classes/' . $session->id . '#materials');
    }

    /** POST /classes/{session}/materials/{file}/delete */
    public function removeMaterial(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        if ($this->materials === null) {
            throw \App\Core\Http\HttpException::notFound();
        }
        $file = $this->materials->remove($tenant, $session, $user, $request->routeInt('file'), $request->clientIp);
        $this->session->flash('success', sprintf('“%s” removed.', $file->originalName));

        return Response::redirect('/classes/' . $session->id . '#materials');
    }

    /** GET /classes */
    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $now = $this->clock->now();
        $nowSql = $now->format('Y-m-d H:i:s');
        $teaches = $user->can(Permission::GroupsManage) || $this->groups->teachesAny($tenant->id, $user->user->id);
        $teacherId = $user->can(Permission::GroupsManage) ? null : $user->user->id;
        $zone = new \DateTimeZone($tenant->timezone);
        $dayEnd = $now->setTimezone($zone)->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $mine = [];
        $past = [];
        if ($user->can(Permission::CourseLearn)) {
            $mine = $this->sessions->forLearner($tenant->id, $user->user->id, $nowSql, $now->modify('+60 days')->format('Y-m-d H:i:s'));
            $past = $this->sessions->learnerSummary($tenant->id, $user->user->id, $nowSql, 12);
        }

        return Response::html($this->view->render('classes/index', [
            'pageTitle' => 'Live classes',
            'mine' => $mine,
            'past' => $past,
            'teaches' => $teaches,
            'myGroups' => $teaches ? array_map(static fn (array $r): array => ['id' => $r['group']->id, 'name' => $r['group']->name], $this->groups->overview($tenant->id, $teacherId)) : [],
            'classroomReady' => $this->live->classroomReady($tenant),
            'meetings' => $this->meetingRepo?->forUser($tenant->id, $user->user->id, $nowSql, $now->modify('+7 days')->format('Y-m-d H:i:s'), 5) ?? [],
            'mayHost' => \App\Modules\Meetings\Meetings::mayHost($user),
            'today' => $teaches ? $this->sessions->forTeacher($tenant->id, $teacherId, $nowSql, $dayEnd) : [],
            'upcoming' => $teaches ? $this->sessions->forTeacher($tenant->id, $teacherId, $dayEnd, $now->modify('+21 days')->format('Y-m-d H:i:s')) : [],
            'registers' => $teaches ? $this->sessions->registersToTake($tenant->id, $teacherId, $nowSql, $now->modify('-30 days')->format('Y-m-d H:i:s')) : [],
            'now' => $now->getTimestamp(),
        ]));
    }

    /** GET /classes/{session} */
    public function show(Request $request): Response
    {
        return $this->render($request);
    }

    /**
     * POST /classes/{session}/join: the learner's check-in. The Join button is
     * a plain link to the meeting; the page sends this alongside it (a form
     * post may not redirect to another site under the page's security policy).
     */
    public function join(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $wantsJson = str_contains((string) $request->header('accept'), 'application/json');
        try {
            $this->live->join($tenant, $session, $user);
        } catch (ValidationException $e) {
            if ($wantsJson) {
                return Response::json(['marked' => false, 'error' => implode(' ', $e->errors)], 409);
            }
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/classes/' . $session->id);
        }
        $mark = $this->sessions->markFor($tenant->id, $session->id, $user->user->id);
        if ($wantsJson) {
            return Response::json(['marked' => $mark !== null, 'status' => $mark['status'] ?? null]);
        }
        $this->session->flash('success', 'You are marked ' . ($mark['status'] ?? 'present') . '.');

        return Response::redirect('/classes/' . $session->id);
    }

    /** POST /classes/{session}/register */
    public function register(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $statuses = $request->inputArray('status');
        $notes = $request->inputArray('note');
        try {
            $n = $this->live->register($tenant, $session, $user, $statuses, $notes, $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/classes/' . $session->id);
        }
        $this->session->flash('success', sprintf('Register saved for %d %s.', $n, $n === 1 ? 'learner' : 'learners'));

        return Response::redirect('/classes/' . $session->id . '#register');
    }

    /** POST /classes/{session}: change the time, place, link or recording. */
    public function update(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $input = [];
        foreach (['title', 'date', 'time', 'minutes', 'mode', 'style', 'location', 'join_url', 'course_id', 'description', 'recording_url'] as $key) {
            $input[$key] = (string) ($request->input($key, '') ?? '');
        }
        try {
            $this->live->update($tenant, $session, $user, $input, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, $input + ['edit_open' => '1'], 422);
        }
        $this->session->flash('success', 'Class updated.');

        return Response::redirect('/classes/' . $session->id);
    }

    /** POST /classes/{session}/cancel */
    public function cancel(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $reason = (string) ($request->input('reason', '') ?? '');
        try {
            $this->live->cancel($tenant, $session, $user, $reason, $request->clientIp);
        } catch (ValidationException $e) {
            return $this->render($request, $e->errors, ['reason' => $reason, 'cancel_open' => '1'], 422);
        }
        $this->session->flash('success', 'Class cancelled. Learners have been told.');

        return Response::redirect('/classes/' . $session->id);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $session = $this->live->sessionFor($tenant, $request->routeInt('session'), $user);
        $group = $this->groups->find($tenant->id, $session->groupId);
        $teaches = $group !== null && $this->live->mayTeach($tenant, $group, $user);
        [$date, $time] = $session->localStart($tenant->timezone);

        $materials = $this->materials?->list($tenant, $session) ?? [];
        $recording = $this->materials?->recording($tenant, $session);

        return Response::html($this->view->render('classes/show', [
            'pageTitle' => $session->title,
            'session' => $session,
            'teaches' => $teaches,
            'materials' => array_values(array_filter($materials, static fn ($f): bool => $f->id !== $recording?->id)),
            'recording' => $recording,
            'classroomReady' => $this->live->classroomReady($tenant),
            'minutesIn' => $teaches && $this->classroom !== null ? $this->classroom->minutes($tenant, $session) : [],
            'policy' => $this->fileService?->policy(),
            'learners' => $teaches ? $this->groups->members($tenant->id, $session->groupId, GroupRepository::LEARNER) : [],
            'marks' => $teaches ? $this->sessions->marks($tenant->id, $session->id) : [],
            'myMark' => $teaches ? null : $this->sessions->markFor($tenant->id, $session->id, $user->user->id),
            'teachers' => $this->groups->members($tenant->id, $session->groupId, GroupRepository::TEACHER),
            'courses' => $teaches ? $this->courses->listPublished($tenant->id, 100) : [],
            'now' => $this->clock->now()->getTimestamp(),
            'errors' => $errors,
            'old' => $old + [
                'title' => $session->title, 'date' => $date, 'time' => $time, 'minutes' => (string) $session->minutes(), 'mode' => $session->mode, 'style' => $session->style,
                'location' => $session->location, 'join_url' => (string) $session->joinUrl, 'course_id' => (string) ($session->courseId ?? ''),
                'description' => $session->description, 'recording_url' => (string) $session->recordingUrl, 'reason' => '',
            ],
        ]), $status);
    }

    /** For other pages: whether a session is happening or about to. */
    public static function isNow(ClassSession $s, int $now): bool
    {
        return in_array($s->phase($now), ['open', 'live'], true);
    }
}
