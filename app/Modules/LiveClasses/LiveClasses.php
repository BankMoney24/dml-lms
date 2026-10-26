<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\CourseRepository;
use App\Modules\Groups\GroupRepository;
use App\Modules\Groups\GroupService;
use App\Modules\Groups\LearnerGroup;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Live classes and attendance (ADR 0028), and the online classroom (ADR 0029).
 *  - The group's teachers (and administrators) schedule sessions: once or
 *    weekly, online with a meeting link, in a room, or both. Times are typed
 *    in the school's time zone and stored in UTC.
 *  - Learners in the group see them on their home page and calendar. Pressing
 *    Join during the session marks them present (late after 10 minutes) and
 *    opens the meeting; the teacher's register can change any mark.
 *  - Learners hear about new sessions and cancellations; parents who follow
 *    notices hear when their child is marked absent.
 */
final class LiveClasses
{
    public const MAX_WEEKS = 15;
    public const NOTIFY_SCHEDULED = 'class.scheduled';
    public const NOTIFY_CANCELLED = 'class.cancelled';
    public const NOTIFY_ABSENT = 'class.absent';
    public const NOTIFY_LIVE = 'class.live';
    /** Lengths offered for a class started there and then. */
    public const QUICK_MINUTES = [30, 45, 60, 90, 120];
    /** Most a running class can be stretched to, in total. */
    public const MAX_MINUTES = 480;

    public function __construct(
        private readonly ClassSessionRepository $sessions,
        private readonly GroupRepository $groups,
        private readonly GroupService $groupService,
        private readonly CourseRepository $courses,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ?GuardianRepository $guardians = null,
        private readonly ?Notifier $notifier = null,
        private readonly ?Classroom\ClassroomSettingsRepository $classroom = null,
    ) {
    }

    /** Whether the school has its online classroom set up (ADR 0029). */
    public function classroomReady(Tenant $tenant): bool
    {
        return $this->classroom?->find($tenant->id) !== null;
    }

    /** Whether this person may see a class's materials and recording. */
    public function maySeeFilesOf(Tenant $tenant, int $sessionId, CurrentUser $user): bool
    {
        try {
            $this->sessionFor($tenant, $sessionId, $user);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function mayTeach(Tenant $tenant, LearnerGroup $group, CurrentUser $user): bool
    {
        return $this->groupService->mayTeach($tenant, $group, $user);
    }

    /** The session, if this person teaches its group or learns in it. */
    public function sessionFor(Tenant $tenant, int $id, CurrentUser $user): ClassSession
    {
        $session = $this->sessions->find($tenant->id, $id) ?? throw HttpException::notFound();
        $group = $this->groups->find($tenant->id, $session->groupId) ?? throw HttpException::notFound();
        if (!$this->mayTeach($tenant, $group, $user) && $this->groups->roleOf($tenant->id, $group->id, $user->user->id) !== 'learner') {
            throw HttpException::notFound();
        }

        return $session;
    }

    /**
     * Schedules one session, or the same slot weekly.
     *
     * @param array<string, string> $input title, date, time, minutes, mode, location, join_url, course_id, weeks, description
     * @return list<int>
     */
    public function schedule(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, array $input, string $clientIp): array
    {
        $this->authorise($tenant, $group, $actor);
        $v = $this->valid($tenant, $group, $input, true);
        $weeks = $v['weeks'];
        $series = $weeks > 1 ? bin2hex(random_bytes(8)) : null;
        $ids = [];
        for ($w = 0; $w < $weeks; $w++) {
            $start = $v['start']->modify('+' . $w . ' weeks');
            $ids[] = $this->sessions->create($tenant->id, [
                'group_id' => $group->id,
                'course_id' => $v['course_id'],
                'series_id' => $series,
                'title' => $v['title'],
                'description' => $v['description'],
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $start->modify('+' . $v['minutes'] . ' minutes')->format('Y-m-d H:i:s'),
                'mode' => $v['mode'],
                'style' => $v['style'],
                'location' => $v['location'],
                'join_url' => $v['join_url'],
                'room_name' => $v['mode'] === 'classroom' ? ClassSession::newRoomName() : null,
                'created_by' => $actor->user->id,
            ]);
        }
        $this->audit->record($tenant->id, $actor->user->id, 'class.scheduled', 'group', $group->id, ['sessions' => count($ids)], $clientIp);
        $local = $v['start']->setTimezone(new DateTimeZone($tenant->timezone));
        $this->notifier?->notifyMany(
            $tenant->id,
            $this->learnerIds($tenant, $group),
            self::NOTIFY_SCHEDULED,
            sprintf('Live class: %s, %s', $v['title'], $local->format('D j M, H:i')),
            $weeks > 1 ? sprintf('Every week at the same time, %d sessions. It is on your calendar.', $weeks) : 'It is on your home page and calendar.',
            '/classes/' . $ids[0],
        );

        return $ids;
    }

    /** @param array<string, string> $input */
    public function update(Tenant $tenant, ClassSession $session, CurrentUser $actor, array $input, string $clientIp): void
    {
        $group = $this->groupOf($tenant, $session);
        $this->authorise($tenant, $group, $actor);
        if ($session->isCancelled()) {
            throw ValidationException::single('form', 'This class was cancelled. Schedule a new one instead.');
        }
        $v = $this->valid($tenant, $group, $input, false);
        $recording = trim($input['recording_url'] ?? '');
        if ($recording !== '' && !self::isWebAddress($recording)) {
            throw ValidationException::single('recording_url', 'Paste the full recording address, starting with https://.');
        }
        $this->sessions->update($tenant->id, $session->id, [
            'title' => $v['title'],
            'description' => $v['description'],
            'course_id' => $v['course_id'],
            'starts_at' => $v['start']->format('Y-m-d H:i:s'),
            'ends_at' => $v['start']->modify('+' . $v['minutes'] . ' minutes')->format('Y-m-d H:i:s'),
            'mode' => $v['mode'],
            'style' => $v['style'],
            'location' => $v['location'],
            'join_url' => $v['join_url'],
            'recording_url' => $recording === '' ? null : $recording,
            'room_name' => $v['mode'] === 'classroom' ? ClassSession::newRoomName() : null, // kept if the class already has one
        ]);
        $this->audit->record($tenant->id, $actor->user->id, 'class.updated', 'class_session', $session->id, [], $clientIp);
    }

    public function cancel(Tenant $tenant, ClassSession $session, CurrentUser $actor, string $reason, string $clientIp): void
    {
        $group = $this->groupOf($tenant, $session);
        $this->authorise($tenant, $group, $actor);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw ValidationException::single('reason', 'Tell learners why, for example “The teacher is unwell; we will catch up next week”.');
        }
        if ($session->isCancelled()) {
            return;
        }
        $this->sessions->cancel($tenant->id, $session->id, $reason);
        $this->audit->record($tenant->id, $actor->user->id, 'class.cancelled', 'class_session', $session->id, [], $clientIp);
        if (!$session->hasEnded($this->clock->now()->getTimestamp())) {
            $local = (new DateTimeImmutable($session->startsAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant->timezone));
            $this->notifier?->notifyMany(
                $tenant->id,
                $this->learnerIds($tenant, $group),
                self::NOTIFY_CANCELLED,
                sprintf('Cancelled: %s, %s', $session->title, $local->format('D j M, H:i')),
                $reason,
                '/classes/' . $session->id,
            );
        }
    }

    /**
     * A learner presses Join: during the session (or just before) they are
     * marked present, or late after the first minutes, unless already marked.
     * Returns the meeting address to open.
     */
    public function join(Tenant $tenant, ClassSession $session, CurrentUser $learner): string
    {
        $now = $this->clock->now()->getTimestamp();
        $phase = $session->phase($now);
        if (!$session->isOnline() || !in_array($phase, ['open', 'live'], true)) {
            throw ValidationException::single('join', $phase === 'before' ? 'The class opens 15 minutes before it starts.' : 'This class is not running now.');
        }
        if ($this->groups->roleOf($tenant->id, $session->groupId, $learner->user->id) === 'learner') {
            $this->sessions->checkIn($tenant->id, $session->id, $learner->user->id, $session->isLate($now) ? 'late' : 'present');
        }

        return (string) $session->joinUrl;
    }

    /**
     * The teacher's register: a status (and an optional note) per learner.
     *
     * @param array<int|string, string> $statuses user id => status
     * @param array<int|string, string> $notes    user id => note
     */
    public function register(Tenant $tenant, ClassSession $session, CurrentUser $actor, array $statuses, array $notes, string $clientIp): int
    {
        $group = $this->groupOf($tenant, $session);
        $this->authorise($tenant, $group, $actor);
        if ($session->isCancelled()) {
            throw ValidationException::single('form', 'This class was cancelled, so there is no register.');
        }
        if ($session->phase($this->clock->now()->getTimestamp()) === 'before') {
            throw ValidationException::single('form', 'Take the register once the class has started.');
        }
        $learners = array_column($this->groups->members($tenant->id, $group->id, 'learner'), 'name', 'user_id');
        $before = $this->sessions->marks($tenant->id, $session->id);
        $marked = 0;
        $absent = [];
        foreach ($statuses as $userId => $status) {
            $userId = (int) $userId;
            if (!isset($learners[$userId]) || !isset(ClassSessionRepository::STATUSES[$status])) {
                continue;
            }
            $note = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $notes[$userId] ?? $notes[(string) $userId] ?? '')), 0, 200);
            $this->sessions->mark($tenant->id, $session->id, $userId, $status, $note, $actor->user->id);
            $marked++;
            if ($status === 'absent' && ($before[$userId]['status'] ?? null) !== 'absent') {
                $absent[] = $userId;
            }
        }
        $this->audit->record($tenant->id, $actor->user->id, 'class.register', 'class_session', $session->id, ['marked' => $marked, 'absent' => count($absent)], $clientIp);
        if ($absent !== [] && $this->guardians !== null) {
            $local = (new DateTimeImmutable($session->startsAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant->timezone));
            foreach ($this->guardians->noticeRecipients($tenant->id, $absent) as $g) {
                $this->notifier?->notify(
                    $tenant->id,
                    $g['guardian_id'],
                    self::NOTIFY_ABSENT,
                    $g['learner_name'] . ' was absent from ' . $session->title,
                    sprintf('%s, %s. Ask the school if you think this is a mistake.', $group->name, $local->format('D j M, H:i')),
                    '/children',
                );
            }
        }

        return $marked;
    }

    /**
     * A class started there and then in the online classroom (ADR 0030): no
     * planning, the learners are told it is live, and the teacher goes
     * straight in. Returns the new class.
     */
    public function startNow(Tenant $tenant, LearnerGroup $group, CurrentUser $actor, string $title, int $minutes, string $style, string $clientIp): ClassSession
    {
        $this->authorise($tenant, $group, $actor);
        if (!$this->classroomReady($tenant)) {
            throw ValidationException::single('quick', 'The online classroom is not set up for this school yet. An administrator can connect it under School, Classroom.');
        }
        if (!in_array($minutes, self::QUICK_MINUTES, true)) {
            throw ValidationException::single('quick', 'Choose how long the class will run.');
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            $title = 'Live class: ' . $group->name;
        }
        if (mb_strlen($title) > 160) {
            throw ValidationException::single('quick', 'Keep the title under 160 characters.');
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $start = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        $id = $this->sessions->create($tenant->id, [
            'group_id' => $group->id,
            'course_id' => null,
            'series_id' => null,
            'title' => $title,
            'description' => '',
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
            'mode' => 'classroom',
            'style' => isset(ClassSession::STYLES[$style]) ? $style : 'lecture',
            'location' => '',
            'join_url' => null,
            'room_name' => ClassSession::newRoomName(),
            'created_by' => $actor->user->id,
        ]);
        $this->audit->record($tenant->id, $actor->user->id, 'class.started_now', 'class_session', $id, ['minutes' => $minutes], $clientIp);
        $this->notifier?->notifyMany(
            $tenant->id,
            $this->learnerIds($tenant, $group),
            self::NOTIFY_LIVE,
            'Live now: ' . $title,
            sprintf('%s started a class for %s. Join from your phone or computer.', $actor->user->displayName, $group->name),
            '/classes/' . $id . '/room',
        );

        return $this->sessions->find($tenant->id, $id) ?? throw HttpException::notFound();
    }

    /** Adds time to a class that is on (up to 8 hours in all). Returns the new end, in UTC. */
    public function extend(Tenant $tenant, ClassSession $session, CurrentUser $actor, int $minutes): string
    {
        $group = $this->groupOf($tenant, $session);
        $this->authorise($tenant, $group, $actor);
        $now = $this->clock->now()->getTimestamp();
        if ($session->isCancelled() || $session->hasEnded($now) || !in_array($minutes, [15, 30, 60], true)) {
            throw ValidationException::single('extend', 'Only a class that is on can be made longer.');
        }
        $end = $session->endTs() + $minutes * 60;
        if ($end - $session->startTs() > self::MAX_MINUTES * 60) {
            throw ValidationException::single('extend', 'A class can last up to 8 hours.');
        }
        $this->sessions->setEnd($tenant->id, $session->id, gmdate('Y-m-d H:i:s', $end));

        return gmdate('Y-m-d H:i:s', $end);
    }

    /** Ends a class now: the room closes to new joiners and the register can be taken. */
    public function finish(Tenant $tenant, ClassSession $session, CurrentUser $actor, string $clientIp): void
    {
        $group = $this->groupOf($tenant, $session);
        $this->authorise($tenant, $group, $actor);
        $now = $this->clock->now()->getTimestamp();
        if ($session->isCancelled() || $session->hasEnded($now)) {
            return;
        }
        $end = max($now, $session->startTs() + 60);
        $this->sessions->setEnd($tenant->id, $session->id, gmdate('Y-m-d H:i:s', $end));
        $this->audit->record($tenant->id, $actor->user->id, 'class.ended', 'class_session', $session->id, [], $clientIp);
    }

    /** @return list<int> */
    private function learnerIds(Tenant $tenant, LearnerGroup $group): array
    {
        return array_map(static fn (array $m): int => $m['user_id'], $this->groups->members($tenant->id, $group->id, 'learner'));
    }

    private function groupOf(Tenant $tenant, ClassSession $session): LearnerGroup
    {
        return $this->groups->find($tenant->id, $session->groupId) ?? throw HttpException::notFound();
    }

    private function authorise(Tenant $tenant, LearnerGroup $group, CurrentUser $actor): void
    {
        if (!$this->mayTeach($tenant, $group, $actor)) {
            throw HttpException::forbidden();
        }
        if ($group->isArchived()) {
            throw ValidationException::single('form', sprintf('This %s is archived. Restore it to plan classes.', $tenant->groupWord()));
        }
    }

    /**
     * @param array<string, string> $input
     * @return array{title: string, description: string, start: DateTimeImmutable, minutes: int, mode: string, style: string, location: string, join_url: ?string, course_id: ?int,
     *               weeks: int}
     */
    private function valid(Tenant $tenant, LearnerGroup $group, array $input, bool $creating): array
    {
        $errors = [];
        $title = trim((string) preg_replace('/\s+/u', ' ', $input['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) {
            $errors['title'] = 'Give the class a title, for example “Week 3: subnetting practice”.';
        }
        $description = trim(str_replace("\r\n", "\n", $input['description'] ?? ''));
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'Keep the notes under 2,000 characters.';
        }
        $zone = new DateTimeZone($tenant->timezone);
        $date = (string) ($input['date'] ?? '');
        $time = (string) ($input['time'] ?? '');
        $start = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $errors['date'] = 'Choose the day and the start time.';
        } else {
            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
            if ($local === false || $local->format('Y-m-d H:i') !== $date . ' ' . $time) {
                $errors['date'] = 'That day does not exist. Check the date.';
            } else {
                $start = $local->setTimezone(new DateTimeZone('UTC'));
                $now = $this->clock->now();
                if ($creating && $start < $now->modify('-30 days')) {
                    $errors['date'] = 'That is more than 30 days ago.';
                } elseif ($start > $now->modify('+1 year')) {
                    $errors['date'] = 'Plan classes up to a year ahead.';
                }
            }
        }
        $minutes = (int) ($input['minutes'] ?? 0);
        if ($minutes < 15 || $minutes > 480) {
            $errors['minutes'] = 'A class lasts between 15 minutes and 8 hours.';
        }
        $mode = (string) ($input['mode'] ?? '');
        if (!isset(ClassSession::MODES[$mode])) {
            $errors['mode'] = 'Choose where the class happens.';
        }
        $style = (string) ($input['style'] ?? 'discussion');
        if (!isset(ClassSession::STYLES[$style])) {
            $style = 'discussion';
        }
        $location = trim((string) preg_replace('/\s+/u', ' ', $input['location'] ?? ''));
        $link = trim($input['join_url'] ?? '');
        if (in_array($mode, ['room', 'both'], true) && ($location === '' || mb_strlen($location) > 200)) {
            $errors['location'] = 'Say which room, for example “Lab 2, first floor”.';
        }
        if (in_array($mode, ['online', 'both'], true) && !self::isWebAddress($link)) {
            $errors['join_url'] = 'Paste the meeting link (Google Meet, Zoom, Teams or Jitsi), starting with https://.';
        }
        if ($mode === 'classroom' && !$this->classroomReady($tenant)) {
            $errors['mode'] = 'The online classroom is not set up for this school yet. An administrator can set it up under School, Classroom; until then use a meeting link.';
        }
        $courseId = null;
        if (($input['course_id'] ?? '') !== '') {
            $courseId = ctype_digit($input['course_id']) ? (int) $input['course_id'] : 0;
            if ($this->courses->findById($tenant->id, $courseId) === null) {
                $errors['course_id'] = 'Choose one of the school’s courses, or none.';
            }
        }
        $weeks = $creating ? (int) ($input['weeks'] ?? 1) : 1;
        if ($weeks < 1 || $weeks > self::MAX_WEEKS) {
            $errors['weeks'] = sprintf('Repeat for 1 to %d weeks.', self::MAX_WEEKS);
        }
        if ($errors !== [] || $start === null) {
            throw new ValidationException($errors === [] ? ['date' => 'Choose the day and the start time.'] : $errors);
        }

        return [
            'title' => $title,
            'description' => $description,
            'start' => $start,
            'minutes' => $minutes,
            'mode' => $mode,
            'style' => $style,
            'location' => in_array($mode, ['room', 'both'], true) ? $location : '',
            'join_url' => in_array($mode, ['online', 'both'], true) ? $link : null,
            'course_id' => $courseId,
            'weeks' => $weeks,
        ];
    }

    /** An https (or http) address with a host: nothing that could run script when opened. */
    public static function isWebAddress(string $url): bool
    {
        if (mb_strlen($url) > 500 || preg_match('#^https?://[^\s<>"\']+$#i', $url) !== 1) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && str_contains($host, '.');
    }
}
