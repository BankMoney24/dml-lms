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
    ) {
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
        foreach (['title', 'date', 'time', 'minutes', 'mode', 'location', 'join_url', 'course_id', 'description', 'recording_url'] as $key) {
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

        return Response::html($this->view->render('classes/show', [
            'pageTitle' => $session->title,
            'session' => $session,
            'teaches' => $teaches,
            'learners' => $teaches ? $this->groups->members($tenant->id, $session->groupId, GroupRepository::LEARNER) : [],
            'marks' => $teaches ? $this->sessions->marks($tenant->id, $session->id) : [],
            'myMark' => $teaches ? null : $this->sessions->markFor($tenant->id, $session->id, $user->user->id),
            'teachers' => $this->groups->members($tenant->id, $session->groupId, GroupRepository::TEACHER),
            'courses' => $teaches ? $this->courses->listPublished($tenant->id, 100) : [],
            'now' => $this->clock->now()->getTimestamp(),
            'errors' => $errors,
            'old' => $old + [
                'title' => $session->title, 'date' => $date, 'time' => $time, 'minutes' => (string) $session->minutes(), 'mode' => $session->mode,
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
