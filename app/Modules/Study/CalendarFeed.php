<?php

declare(strict_types=1);

namespace App\Modules\Study;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Modules\Tenancy\Tenant;

/**
 * A private calendar link (iCalendar) with a learner's due dates, for phone
 * and computer calendars. The link holds a random secret; only its SHA-256 is
 * stored, so the link is shown once and can be replaced at any time. It
 * shows course titles and dates only.
 */
final class CalendarFeed
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Deadlines $deadlines,
        private readonly ?\App\Modules\LiveClasses\ClassSessionRepository $classes = null,
    ) {
    }

    /** Makes a new secret (the old link stops working) and returns it, once. */
    public function issue(int $tenantId, int $userId): string
    {
        $token = bin2hex(random_bytes(20));
        $this->db->execute(
            'UPDATE memberships SET calendar_token_hash = ?, updated_at = ? WHERE tenant_id = ? AND user_id = ?',
            [hash('sha256', $token), $this->clock->sqlNow(), $tenantId, $userId],
        );

        return $token;
    }

    public function revoke(int $tenantId, int $userId): void
    {
        $this->db->execute(
            'UPDATE memberships SET calendar_token_hash = NULL, updated_at = ? WHERE tenant_id = ? AND user_id = ?',
            [$this->clock->sqlNow(), $tenantId, $userId],
        );
    }

    public function hasLink(int $tenantId, int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT calendar_token_hash FROM memberships WHERE tenant_id = ? AND user_id = ?',
            [$tenantId, $userId],
        ) !== null;
    }

    /** The member a secret belongs to, if it is current and they are still active in this school. */
    public function userFor(int $tenantId, string $token): ?int
    {
        if (preg_match('/^[0-9a-f]{40}$/', $token) !== 1) {
            return null;
        }
        $id = $this->db->fetchValue(
            "SELECT m.user_id FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.tenant_id = ? AND m.calendar_token_hash = ? AND m.status = 'active' AND u.status = 'active'",
            [$tenantId, hash('sha256', $token)],
        );

        return $id === null ? null : (int) $id;
    }

    public function render(Tenant $tenant, int $userId, string $baseUrl): string
    {
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'dml.local');
        $stamp = $this->clock->now()->format('Ymd\THis\Z');
        $from = $this->clock->now()->modify('-60 days')->format('Y-m-d');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//DML Technologies//Learning Platform//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($tenant->name . ' classes and due dates'),
            'X-WR-TIMEZONE:' . self::text($tenant->timezone),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];
        foreach ($this->deadlines->forLearner($tenant->id, $userId, $from) as $d) {
            $day = str_replace('-', '', $d['due_on']);
            $next = (new \DateTimeImmutable($d['due_on']))->modify('+1 day')->format('Ymd');
            $url = $baseUrl . '/courses/' . rawurlencode($d['slug']);
            array_push(
                $lines,
                'BEGIN:VEVENT',
                'UID:' . self::text('due-' . $tenant->id . '-' . $userId . '-' . $d['course_id'] . '@' . $host),
                'DTSTAMP:' . $stamp,
                'DTSTART;VALUE=DATE:' . $day,
                'DTEND;VALUE=DATE:' . $next,
                'SUMMARY:' . self::text('Due: ' . $d['title']),
                'DESCRIPTION:' . self::text('Set for ' . $d['group'] . '. Open the course: ' . $url),
                'URL:' . self::text($url),
                'TRANSP:TRANSPARENT',
                'END:VEVENT',
            );
        }
        // Live classes (ADR 0028): the ones this person attends and the ones they teach.
        if ($this->classes !== null) {
            $now = $this->clock->now();
            $from = $now->modify('-30 days')->format('Y-m-d H:i:s');
            $to = $now->modify('+120 days')->format('Y-m-d H:i:s');
            $seen = [];
            $teacher = $this->db->fetchValue("SELECT 1 FROM learner_group_members WHERE tenant_id = ? AND user_id = ? AND role = 'teacher' LIMIT 1", [$tenant->id, $userId]) !== null;
            $sessions = [...$this->classes->forLearner($tenant->id, $userId, $from, $to, 200), ...($teacher ? $this->classes->forTeacher($tenant->id, $userId, $from, $to, 200) : [])];
            foreach ($sessions as $c) {
                if (isset($seen[$c->id])) {
                    continue;
                }
                $seen[$c->id] = true;
                $url = $baseUrl . '/classes/' . $c->id;
                array_push(
                    $lines,
                    'BEGIN:VEVENT',
                    'UID:' . self::text('class-' . $tenant->id . '-' . $c->id . '@' . $host),
                    'DTSTAMP:' . $stamp,
                    'DTSTART:' . (new \DateTimeImmutable($c->startsAt . ' UTC'))->format('Ymd\THis\Z'),
                    'DTEND:' . (new \DateTimeImmutable($c->endsAt . ' UTC'))->format('Ymd\THis\Z'),
                    'SUMMARY:' . self::text(($c->isCancelled() ? 'Cancelled: ' : '') . $c->title . ' (' . $c->groupName . ')'),
                    'LOCATION:' . self::text($c->inRoom() ? $c->location . ($c->mode === 'both' ? ', and online' : '') : 'Online'),
                    'DESCRIPTION:' . self::text(($c->isOnline() ? 'Join from the class page: ' : 'Details: ') . $url),
                    'URL:' . self::text($url),
                    'STATUS:' . ($c->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
                    'END:VEVENT',
                );
            }
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    /** RFC 5545 text: escape backslash, semicolon, comma and line breaks. */
    private static function text(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line after a space, without splitting a character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === [] ? 75 : 74)) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
