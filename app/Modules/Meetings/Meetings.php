<?php

declare(strict_types=1);

namespace App\Modules\Meetings;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\LiveClasses\Classroom\Classroom;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Virtual meetings in the school's online classroom (ADR 0030).
 *  - Staff host them: now ("meet now") or at a time, for up to 3 hours.
 *  - The host invites people from the school: all staff, a class (its
 *    learners and teachers), a class's parents, or named people by email.
 *    Invitations are resolved to people at that moment and they are told.
 *  - Only the host and the people invited can get in, from 15 minutes
 *    before (30 for the host) until the end. The host runs the room; other
 *    staff can too when the host allows it.
 *  - Joins and "still here" signals show who came and for how long.
 */
final class Meetings
{
    public const NOTIFY_INVITED = 'meeting.invited';
    public const NOTIFY_LIVE = 'meeting.live';
    public const NOTIFY_CANCELLED = 'meeting.cancelled';
    public const PASS_GRACE_MINUTES = 15;
    public const MAX_INVITES = 500;
    public const MAX_MINUTES = 240;

    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly Classroom $classroom,
        private readonly GroupRepository $groups,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /** Staff host meetings. */
    public static function mayHost(CurrentUser $user): bool
    {
        return $user->can(Permission::CourseManage) || $user->can(Permission::GroupsManage);
    }

    public function ready(Tenant $tenant): bool
    {
        return $this->classroom->settingsFor($tenant) !== null;
    }

    /**
     * The classes this host may invite as a whole (all for administrators,
     * their own otherwise).
     *
     * @return array<int, string> group id => name
     */
    public function invitableGroups(Tenant $tenant, CurrentUser $host): array
    {
        $teacherId = $host->can(Permission::GroupsManage) ? null : $host->user->id;
        $out = [];
        foreach ($this->groups->overview($tenant->id, $teacherId) as $row) {
            $out[$row['group']->id] = $row['group']->name;
        }

        return $out;
    }

    /**
     * Plans a meeting (or starts one now) and invites people.
     *
     * @param array<string, mixed> $input title, agenda, when (now|later), date, time, minutes, staff_moderate,
     *                                    staff, groups (ids), parents (group ids), emails
     */
    public function create(Tenant $tenant, CurrentUser $host, array $input, string $clientIp): Meeting
    {
        if (!self::mayHost($host)) {
            throw HttpException::forbidden();
        }
        if (!$this->ready($tenant)) {
            throw ValidationException::single('title', 'The online classroom is not set up for this school yet. An administrator can connect it under School, Classroom.');
        }
        $errors = [];
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) {
            $errors['title'] = 'Give the meeting a title, for example “Staff meeting” or “SS 2 parents’ evening”.';
        }
        $agenda = trim(str_replace("\r\n", "\n", (string) ($input['agenda'] ?? '')));
        if (mb_strlen($agenda) > 2000) {
            $errors['agenda'] = 'Keep the agenda under 2,000 characters.';
        }
        $minutes = (int) ($input['minutes'] ?? 0);
        if (!in_array($minutes, Meeting::MINUTES, true)) {
            $errors['minutes'] = 'Choose how long the meeting runs.';
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $startNow = ($input['when'] ?? 'now') !== 'later';
        $start = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        if (!$startNow) {
            $date = (string) ($input['date'] ?? '');
            $time = (string) ($input['time'] ?? '');
            $local = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone($tenant->timezone))
                : false;
            if ($local === false || $local->format('Y-m-d H:i') !== $date . ' ' . $time) {
                $errors['date'] = 'Choose the day and the start time.';
            } else {
                $start = $local->setTimezone(new DateTimeZone('UTC'));
                if ($start < $now->modify('-5 minutes')) {
                    $errors['date'] = 'That time has passed. To meet straight away, choose “Now”.';
                } elseif ($start > $now->modify('+1 year')) {
                    $errors['date'] = 'Plan meetings up to a year ahead.';
                }
            }
        }
        [$invitees, $unknown] = $this->resolve($tenant, $host, $input);
        if ($unknown !== []) {
            $errors['emails'] = sprintf('Not members of this school: %s. Check the addresses or add them under People first.', implode(', ', array_slice($unknown, 0, 5)));
        }
        $invitees = array_values(array_diff($invitees, [$host->user->id]));
        if ($invitees === [] && !isset($errors['emails'])) {
            $errors['invite'] = 'Invite at least one person: all staff, a class, its parents, or people by email.';
        }
        if (count($invitees) > self::MAX_INVITES) {
            $errors['invite'] = sprintf('A meeting can have up to %d people. Split it into smaller meetings.', self::MAX_INVITES);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->meetings->create($tenant->id, [
            'host_id' => $host->user->id,
            'title' => $title,
            'agenda' => $agenda,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
            'room_name' => Meeting::newRoomName(),
            'staff_moderate' => ($input['staff_moderate'] ?? '') === '1',
        ]);
        $added = $this->meetings->invite($tenant->id, $id, $invitees);
        $meeting = $this->meetings->find($tenant->id, $id) ?? throw HttpException::notFound();
        $this->audit->record($tenant->id, $host->user->id, $startNow ? 'meeting.started_now' : 'meeting.planned', 'meeting', $id, ['invited' => count($added)], $clientIp);
        $this->tell($tenant, $meeting, $host, $added, $startNow);

        return $meeting;
    }

    /**
     * Invites more people to a meeting that has not ended.
     *
     * @param array<string, mixed> $input staff, groups, parents, emails
     */
    public function inviteMore(Tenant $tenant, Meeting $meeting, CurrentUser $host, array $input, string $clientIp): int
    {
        $this->authoriseHost($meeting, $host);
        if ($meeting->isCancelled() || $meeting->hasEnded($this->clock->now()->getTimestamp())) {
            throw ValidationException::single('invite', 'This meeting is over.');
        }
        [$invitees, $unknown] = $this->resolve($tenant, $host, $input);
        if ($unknown !== []) {
            throw ValidationException::single('emails', sprintf('Not members of this school: %s.', implode(', ', array_slice($unknown, 0, 5))));
        }
        $invitees = array_values(array_diff($invitees, [$host->user->id]));
        if ($meeting->invited + count($invitees) > self::MAX_INVITES) {
            throw ValidationException::single('invite', sprintf('A meeting can have up to %d people.', self::MAX_INVITES));
        }
        $added = $this->meetings->invite($tenant->id, $meeting->id, $invitees);
        $this->audit->record($tenant->id, $host->user->id, 'meeting.invited', 'meeting', $meeting->id, ['invited' => count($added)], $clientIp);
        $this->tell($tenant, $meeting, $host, $added, $meeting->phase($this->clock->now()->getTimestamp()) !== 'before');

        return count($added);
    }

    /** The meeting, if this person hosts it or is invited; otherwise not found. */
    public function meetingFor(Tenant $tenant, int $id, CurrentUser $user): Meeting
    {
        $meeting = $this->meetings->find($tenant->id, $id) ?? throw HttpException::notFound();
        if ($meeting->hostId !== $user->user->id && !$this->meetings->isInvited($tenant->id, $meeting->id, $user->user->id)) {
            throw HttpException::notFound();
        }

        return $meeting;
    }

    public function isHost(Meeting $meeting, CurrentUser $user): bool
    {
        return $meeting->hostId === $user->user->id;
    }

    /** The host runs the room; invited staff too when the host allows it. */
    public function runsRoom(Tenant $tenant, Meeting $meeting, CurrentUser $user): bool
    {
        if ($this->isHost($meeting, $user)) {
            return true;
        }

        return $meeting->staffModerate && in_array($this->meetings->roleOf($tenant->id, $user->user->id), MeetingRepository::STAFF_ROLES, true);
    }

    /** @return array{ok: bool, reason: string} reason: '' | not-set-up | before | ended | cancelled */
    public function access(Tenant $tenant, Meeting $meeting, bool $runsRoom): array
    {
        if (!$this->ready($tenant)) {
            return ['ok' => false, 'reason' => 'not-set-up'];
        }
        $now = $this->clock->now()->getTimestamp();
        $phase = $meeting->phase($now);
        if ($phase === 'cancelled' || $phase === 'ended') {
            return ['ok' => false, 'reason' => $phase];
        }
        if ($phase === 'before' && !($runsRoom && $now >= $meeting->startTs() - Meeting::HOST_EARLY_MINUTES * 60)) {
            return ['ok' => false, 'reason' => 'before'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /** @return array{domain: string, room: string, token: string, expires: int, moderator: bool, lowData: bool} */
    public function pass(Tenant $tenant, Meeting $meeting, CurrentUser $user): array
    {
        $runs = $this->runsRoom($tenant, $meeting, $user);
        if (!$this->access($tenant, $meeting, $runs)['ok']) {
            throw ValidationException::single('room', 'The meeting is not open now.');
        }

        return $this->classroom->issue($tenant, $meeting->roomName, $user, $runs, $meeting->endTs() + self::PASS_GRACE_MINUTES * 60);
    }

    public function signal(Tenant $tenant, Meeting $meeting, CurrentUser $user, string $type): void
    {
        if (!in_array($type, ['join', 'here', 'leave'], true)) {
            throw ValidationException::single('type', 'Unknown signal.');
        }
        if (!$this->access($tenant, $meeting, $this->runsRoom($tenant, $meeting, $user))['ok']) {
            throw ValidationException::single('room', 'The meeting is not on now.');
        }
        $this->meetings->recordEvent($tenant->id, $meeting->id, $user->user->id, $type);
    }

    /** Adds 15, 30 or 60 minutes to a meeting that is on. Returns the new end (UTC). */
    public function extend(Meeting $meeting, CurrentUser $host, int $minutes): string
    {
        $this->authoriseHost($meeting, $host);
        $now = $this->clock->now()->getTimestamp();
        if ($meeting->isCancelled() || $meeting->hasEnded($now) || !in_array($minutes, [15, 30, 60], true)) {
            throw ValidationException::single('extend', 'Only a meeting that is on can be made longer.');
        }
        $end = $meeting->endTs() + $minutes * 60;
        if ($end - $meeting->startTs() > self::MAX_MINUTES * 60) {
            throw ValidationException::single('extend', 'A meeting can last up to 4 hours.');
        }
        $this->meetings->setEnd($meeting->tenantId, $meeting->id, gmdate('Y-m-d H:i:s', $end));

        return gmdate('Y-m-d H:i:s', $end);
    }

    /** Ends the meeting now: no one else can join. */
    public function finish(Meeting $meeting, CurrentUser $host, string $clientIp): void
    {
        $this->authoriseHost($meeting, $host);
        $now = $this->clock->now()->getTimestamp();
        if ($meeting->isCancelled() || $meeting->hasEnded($now)) {
            return;
        }
        $this->meetings->setEnd($meeting->tenantId, $meeting->id, gmdate('Y-m-d H:i:s', max($now, $meeting->startTs() + 60)));
        $this->audit->record($meeting->tenantId, $host->user->id, 'meeting.ended', 'meeting', $meeting->id, [], $clientIp);
    }

    public function cancel(Tenant $tenant, Meeting $meeting, CurrentUser $host, string $reason, string $clientIp): void
    {
        $this->authoriseHost($meeting, $host);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw ValidationException::single('reason', 'Tell people why, for example “Moved to Friday; a new invitation follows”.');
        }
        if ($meeting->isCancelled() || $meeting->hasEnded($this->clock->now()->getTimestamp())) {
            return;
        }
        $this->meetings->cancel($tenant->id, $meeting->id, $reason);
        $this->audit->record($tenant->id, $host->user->id, 'meeting.cancelled', 'meeting', $meeting->id, [], $clientIp);
        $this->notifier?->notifyMany(
            $tenant->id,
            array_map(static fn (array $i): int => $i['user_id'], $this->meetings->invitees($tenant->id, $meeting->id)),
            self::NOTIFY_CANCELLED,
            'Cancelled: ' . $meeting->title,
            $reason,
            '/meetings/' . $meeting->id,
        );
    }

    /**
     * Turns the invitation choices into people.
     *
     * @param array<string, mixed> $input
     * @return array{0: list<int>, 1: list<string>} people, and email addresses that are not members
     */
    private function resolve(Tenant $tenant, CurrentUser $host, array $input): array
    {
        $ids = [];
        if (($input['staff'] ?? '') === '1') {
            $ids = array_merge($ids, $this->meetings->staffIds($tenant->id));
        }
        $allowed = $this->invitableGroups($tenant, $host);
        foreach (self::idList($input['groups'] ?? []) as $groupId) {
            if (isset($allowed[$groupId])) {
                $ids = array_merge($ids, $this->meetings->groupMemberIds($tenant->id, $groupId));
            }
        }
        foreach (self::idList($input['parents'] ?? []) as $groupId) {
            if (isset($allowed[$groupId])) {
                $ids = array_merge($ids, $this->meetings->groupGuardianIds($tenant->id, $groupId));
            }
        }
        $emails = [];
        foreach (preg_split('/[\s,;]+/', strtolower((string) ($input['emails'] ?? ''))) ?: [] as $email) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = true;
            }
        }
        $found = $this->meetings->membersByEmail($tenant->id, array_keys($emails));
        $unknown = array_values(array_diff(array_keys($emails), array_keys($found)));

        return [array_values(array_unique(array_merge($ids, array_values($found)))), $unknown];
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function idList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && ctype_digit($value)) {
                $out[] = (int) $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param list<int> $userIds */
    private function tell(Tenant $tenant, Meeting $meeting, CurrentUser $host, array $userIds, bool $live): void
    {
        if ($userIds === []) {
            return;
        }
        $local = (new DateTimeImmutable($meeting->startsAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tenant->timezone));
        $this->notifier?->notifyMany(
            $tenant->id,
            $userIds,
            $live ? self::NOTIFY_LIVE : self::NOTIFY_INVITED,
            $live ? 'Meeting now: ' . $meeting->title : sprintf('Meeting: %s, %s', $meeting->title, $local->format('D j M, H:i')),
            $live
                ? sprintf('%s is waiting for you in the online classroom.', $host->user->displayName)
                : sprintf('%s invited you. It is on your calendar; join from the meeting page.', $host->user->displayName),
            $live ? '/meetings/' . $meeting->id . '/room' : '/meetings/' . $meeting->id,
        );
    }

    private function authoriseHost(Meeting $meeting, CurrentUser $user): void
    {
        if (!$this->isHost($meeting, $user)) {
            throw HttpException::notFound();
        }
    }
}
