<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Classroom;

use App\Core\Clock;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Groups\GroupRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\LiveClasses\ClassSession;
use App\Modules\LiveClasses\ClassSessionRepository;
use App\Modules\Tenancy\Tenant;

/**
 * The school's online classroom (ADR 0029): a self-hosted Jitsi Meet server
 * embedded in the class page.
 *
 * Being signed in is not enough to get in. Before a pass is made the server
 * checks that the person teaches the class's group or learns in it, that the
 * class is in the classroom and that it is on now. The pass is a token signed
 * with the school's secret, for that one unguessable room, and runs out
 * shortly after the class ends.
 */
final class Classroom
{
    /** Teachers can open the room this long before the start, to set up. */
    public const TEACHER_EARLY_MINUTES = 30;
    /** A pass stays usable this long after the class ends (overruns, rejoining). */
    public const PASS_GRACE_MINUTES = 15;
    public const SECRET_MIN_LENGTH = 32;

    public function __construct(
        private readonly ClassroomSettingsRepository $settings,
        private readonly ClassSessionRepository $sessions,
        private readonly GroupRepository $groups,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public function settingsFor(Tenant $tenant): ?ClassroomSettings
    {
        return $this->settings->find($tenant->id);
    }

    /**
     * Saves the school's set-up. The secret is required the first time and
     * otherwise only replaced when a new one is typed.
     *
     * @param array{domain: string, app_id: string, secret: string, low_data: bool} $input
     */
    public function configure(Tenant $tenant, ?CurrentUser $admin, array $input, string $clientIp): void
    {
        $existing = $this->settings->find($tenant->id);
        $domain = strtolower(trim($input['domain']));
        $domain = (string) preg_replace('#^https?://#', '', rtrim($domain, '/'));
        $appId = trim($input['app_id']);
        $secret = trim($input['secret']);
        $errors = [];
        if (!ClassroomSettings::isHostName($domain)) {
            $errors['domain'] = 'Enter the classroom server’s name only, for example live.yourschool.org (no https:// and no path).';
        }
        if (preg_match('/^[A-Za-z0-9_.-]{2,100}$/', $appId) !== 1) {
            $errors['app_id'] = 'Enter the app id set on the Jitsi server: letters, numbers, dots, dashes or underscores.';
        }
        if ($secret === '' && $existing === null) {
            $errors['secret'] = 'Paste the app secret set on the Jitsi server.';
        } elseif ($secret !== '' && (strlen($secret) < self::SECRET_MIN_LENGTH || strlen($secret) > 500 || preg_match('/\s/', $secret) === 1)) {
            $errors['secret'] = sprintf('Use a long random secret of at least %d characters without spaces, for example from “openssl rand -hex 32”.', self::SECRET_MIN_LENGTH);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->settings->save($tenant->id, $domain, $appId, $secret === '' ? null : $secret, $input['low_data'], $admin?->user->id);
        $this->audit->record($tenant->id, $admin?->user->id, $existing === null ? 'classroom.connected' : 'classroom.updated', null, null, [
            'domain' => $domain,
            'secret_changed' => $secret !== '',
        ], $clientIp);
    }

    public function disconnect(Tenant $tenant, CurrentUser $admin, string $clientIp): void
    {
        $this->settings->delete($tenant->id);
        $this->audit->record($tenant->id, $admin->user->id, 'classroom.disconnected', null, null, [], $clientIp);
    }

    /**
     * Whether this person may be in the room right now, and if not, why.
     *
     * @return array{ok: bool, reason: string} reason: '' | not-classroom | not-set-up | before | ended | cancelled
     */
    public function access(Tenant $tenant, ClassSession $session, bool $teaches): array
    {
        if (!$session->inClassroom()) {
            return ['ok' => false, 'reason' => 'not-classroom'];
        }
        if ($this->settings->find($tenant->id) === null) {
            return ['ok' => false, 'reason' => 'not-set-up'];
        }
        $now = $this->clock->now()->getTimestamp();
        $phase = $session->phase($now);
        if ($phase === 'cancelled' || $phase === 'ended') {
            return ['ok' => false, 'reason' => $phase];
        }
        if ($phase === 'before' && !($teaches && $now >= $session->startTs() - self::TEACHER_EARLY_MINUTES * 60)) {
            return ['ok' => false, 'reason' => 'before'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * A signed pass into the class's room, after the checks above. The
     * caller has already confirmed the person belongs to the class
     * (LiveClasses::sessionFor).
     *
     * @return array{domain: string, room: string, token: string, expires: int, moderator: bool, lowData: bool}
     */
    public function pass(Tenant $tenant, ClassSession $session, CurrentUser $user, bool $teaches): array
    {
        $access = $this->access($tenant, $session, $teaches);
        if (!$access['ok'] || $session->roomName === null) {
            throw ValidationException::single('room', 'The classroom is not open for you now.');
        }

        return $this->issue($tenant, $session->roomName, $user, $teaches, $session->endTs() + self::PASS_GRACE_MINUTES * 60);
    }

    /**
     * Signs a pass into one room for one person. Callers check first that the
     * person belongs there and that it is on (classes above, meetings in
     * App\Modules\Meetings).
     *
     * @return array{domain: string, room: string, token: string, expires: int, moderator: bool, lowData: bool}
     */
    public function issue(Tenant $tenant, string $room, CurrentUser $user, bool $moderator, int $expires): array
    {
        $settings = $this->settings->find($tenant->id);
        $secret = $this->settings->secret($tenant->id);
        if ($settings === null || $secret === null) {
            throw ValidationException::single('room', 'The online classroom is not connected.');
        }
        $now = $this->clock->now()->getTimestamp();
        $token = JitsiToken::make($settings, $secret, $room, [
            'id' => sprintf('%d-%d', $tenant->id, $user->user->id),
            'name' => $user->user->displayName,
            'moderator' => $moderator,
        ], $now - 60, $expires);

        return [
            'domain' => $settings->domain,
            'room' => $room,
            'token' => $token,
            'expires' => $expires,
            'moderator' => $moderator,
            'lowData' => $settings->lowData,
        ];
    }

    /**
     * Whether the classroom server answers: it fetches the meeting script the
     * pages load. Says nothing about the token settings, which only a real
     * join can prove.
     *
     * @return array{ok: bool, detail: string}
     */
    public function check(Tenant $tenant): array
    {
        $settings = $this->settings->find($tenant->id);
        if ($settings === null) {
            return ['ok' => false, 'detail' => 'The classroom is not connected.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detail' => 'This server cannot make web requests (PHP curl is missing).'];
        }
        $curl = curl_init($settings->origin() . '/external_api.js');
        if ($curl === false) {
            return ['ok' => false, 'detail' => 'Could not start the check.'];
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_RANGE => '0-2047',
            CURLOPT_USERAGENT => 'DML-LMS classroom check',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status === 0) {
            return ['ok' => false, 'detail' => sprintf('%s did not answer (%s). Check DNS, the certificate and that Jitsi is running.', $settings->domain, $error === '' ? 'no reply' : $error)];
        }
        if ($status >= 400 || (!str_contains($body, 'JitsiMeetExternalAPI') && !str_contains($body, 'function'))) {
            return ['ok' => false, 'detail' => sprintf('%s answered with HTTP %d but not with the Jitsi meeting script.', $settings->domain, $status)];
        }

        return ['ok' => true, 'detail' => sprintf('%s answered and serves the Jitsi meeting script.', $settings->domain)];
    }

    /**
     * A signal from the classroom page: join, still here (once a minute) or
     * leave. The first join of a learner marks them present, or late.
     */
    public function signal(Tenant $tenant, ClassSession $session, CurrentUser $user, string $type, bool $teaches): void
    {
        if (!in_array($type, ['join', 'here', 'leave'], true)) {
            throw ValidationException::single('type', 'Unknown signal.');
        }
        if (!$this->access($tenant, $session, $teaches)['ok']) {
            throw ValidationException::single('room', 'The class is not on now.');
        }
        $this->sessions->recordEvent($tenant->id, $session->id, $user->user->id, $type);
        if ($type === 'join' && !$teaches && $this->groups->roleOf($tenant->id, $session->groupId, $user->user->id) === 'learner') {
            $now = $this->clock->now()->getTimestamp();
            $this->sessions->checkIn($tenant->id, $session->id, $user->user->id, $session->isLate($now) ? 'late' : 'present');
        }
    }

    /** @return array<int, int> user id => about how many minutes they were in the classroom */
    public function minutes(Tenant $tenant, ClassSession $session): array
    {
        return $session->inClassroom() ? $this->sessions->minutesInClassroom($tenant->id, $session) : [];
    }
}
