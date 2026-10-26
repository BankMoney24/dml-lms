<?php

declare(strict_types=1);

namespace App\Modules\Meetings;

use App\Core\Clock;
use App\Core\Database\Connection;

final class MeetingRepository
{
    private const SELECT = 'SELECT m.*, u.display_name AS host_name,
            (SELECT COUNT(*) FROM meeting_invites i WHERE i.tenant_id = m.tenant_id AND i.meeting_id = m.id) AS invited
        FROM meetings m JOIN users u ON u.id = m.host_id';
    public const STAFF_ROLES = ['owner', 'admin', 'educator'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @param array{host_id: int, title: string, agenda: string, starts_at: string, ends_at: string, room_name: string, staff_moderate: bool} $m */
    public function create(int $tenantId, array $m): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO meetings (tenant_id, host_id, title, agenda, starts_at, ends_at, room_name, staff_moderate, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $m['host_id'], $m['title'], $m['agenda'], $m['starts_at'], $m['ends_at'], $m['room_name'], $m['staff_moderate'] ? 1 : 0, 'scheduled', $now, $now],
        );
    }

    public function find(int $tenantId, int $id): ?Meeting
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE m.tenant_id = ? AND m.id = ?', [$tenantId, $id]);

        return $row === null ? null : Meeting::fromRow($row);
    }

    /**
     * Invites people; those already invited are skipped.
     *
     * @param list<int> $userIds
     * @return list<int> the people newly invited
     */
    public function invite(int $tenantId, int $meetingId, array $userIds): array
    {
        $new = [];
        $now = $this->clock->sqlNow();
        foreach (array_values(array_unique($userIds)) as $userId) {
            $added = $this->db->execute(
                'INSERT IGNORE INTO meeting_invites (tenant_id, meeting_id, user_id, invited_at) VALUES (?, ?, ?, ?)',
                [$tenantId, $meetingId, $userId, $now],
            );
            if ($added > 0) {
                $new[] = $userId;
            }
        }

        return $new;
    }

    public function isInvited(int $tenantId, int $meetingId, int $userId): bool
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM meeting_invites WHERE tenant_id = ? AND meeting_id = ? AND user_id = ?', [$tenantId, $meetingId, $userId]) > 0;
    }

    /** @return list<array{user_id: int, name: string, email: string, role: string}> */
    public function invitees(int $tenantId, int $meetingId): array
    {
        return array_map(
            static fn (array $r): array => ['user_id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'role' => (string) $r['role']],
            $this->db->fetchAll(
                'SELECT i.user_id, u.display_name AS name, u.email, COALESCE(ms.role, \'\') AS role
                 FROM meeting_invites i JOIN users u ON u.id = i.user_id
                 LEFT JOIN memberships ms ON ms.tenant_id = i.tenant_id AND ms.user_id = i.user_id
                 WHERE i.tenant_id = ? AND i.meeting_id = ? ORDER BY u.display_name',
                [$tenantId, $meetingId],
            ),
        );
    }

    /** The person's role in the school ('' when not a member). */
    public function roleOf(int $tenantId, int $userId): string
    {
        return (string) ($this->db->fetchValue("SELECT role FROM memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [$tenantId, $userId]) ?? '');
    }

    /**
     * Meetings this person hosts or is invited to that end after $fromUtc and start before $toUtc.
     *
     * @return list<Meeting>
     */
    public function forUser(int $tenantId, int $userId, string $fromUtc, string $toUtc, int $limit = 50): array
    {
        return array_map(Meeting::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE m.tenant_id = ? AND m.ends_at > ? AND m.starts_at < ?
               AND (m.host_id = ? OR EXISTS (SELECT 1 FROM meeting_invites i WHERE i.tenant_id = m.tenant_id AND i.meeting_id = m.id AND i.user_id = ?))
             ORDER BY m.starts_at LIMIT ' . max(1, min(200, $limit)),
            [$tenantId, $fromUtc, $toUtc, $userId, $userId],
        ));
    }

    /** @return list<Meeting> most recent first */
    public function pastForUser(int $tenantId, int $userId, string $beforeUtc, int $limit = 20): array
    {
        return array_map(Meeting::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE m.tenant_id = ? AND m.ends_at <= ?
               AND (m.host_id = ? OR EXISTS (SELECT 1 FROM meeting_invites i WHERE i.tenant_id = m.tenant_id AND i.meeting_id = m.id AND i.user_id = ?))
             ORDER BY m.starts_at DESC LIMIT ' . max(1, min(200, $limit)),
            [$tenantId, $beforeUtc, $userId, $userId],
        ));
    }

    public function cancel(int $tenantId, int $id, string $reason): void
    {
        $this->db->execute(
            "UPDATE meetings SET status = 'cancelled', cancelled_reason = ?, updated_at = ? WHERE tenant_id = ? AND id = ?",
            [$reason, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function setEnd(int $tenantId, int $id, string $endsAtUtc): void
    {
        $this->db->execute('UPDATE meetings SET ends_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$endsAtUtc, $this->clock->sqlNow(), $tenantId, $id]);
    }

    /** Join / still here / leave; a "here" within 40 seconds of the last signal is dropped. */
    public function recordEvent(int $tenantId, int $meetingId, int $userId, string $type): void
    {
        $now = $this->clock->now();
        if ($type === 'here') {
            $recent = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM meeting_events WHERE tenant_id = ? AND meeting_id = ? AND user_id = ? AND type IN ('join', 'here') AND created_at > ?",
                [$tenantId, $meetingId, $userId, $now->modify('-40 seconds')->format('Y-m-d H:i:s')],
            );
            if ($recent > 0) {
                return;
            }
        }
        $this->db->insert(
            'INSERT INTO meeting_events (tenant_id, meeting_id, user_id, type, created_at) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $meetingId, $userId, $type, $now->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Who came, and about how many minutes they were there (distinct minutes with a signal).
     *
     * @return array<int, int> user id => minutes
     */
    public function minutes(int $tenantId, Meeting $meeting): array
    {
        $opens = gmdate('Y-m-d H:i:s', $meeting->startTs() - Meeting::HOST_EARLY_MINUTES * 60);
        $out = [];
        foreach (
            $this->db->fetchAll(
                "SELECT user_id, COUNT(DISTINCT TIMESTAMPDIFF(MINUTE, ?, created_at)) AS minutes
                 FROM meeting_events WHERE tenant_id = ? AND meeting_id = ? AND type IN ('join', 'here') AND created_at >= ? AND created_at <= ?
                 GROUP BY user_id",
                [$opens, $tenantId, $meeting->id, $opens, $meeting->endsAt],
            ) as $row
        ) {
            $out[(int) $row['user_id']] = min((int) $row['minutes'], $meeting->minutes() + Meeting::HOST_EARLY_MINUTES);
        }

        return $out;
    }

    /** @return list<int> active staff (owners, administrators, educators) */
    public function staffIds(int $tenantId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT user_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND role IN ('owner', 'admin', 'educator')",
            [$tenantId],
        ), 'user_id'));
    }

    /** @return list<int> a class's learners and teachers */
    public function groupMemberIds(int $tenantId, int $groupId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT gm.user_id FROM learner_group_members gm
             JOIN memberships ms ON ms.tenant_id = gm.tenant_id AND ms.user_id = gm.user_id AND ms.status = \'active\'
             WHERE gm.tenant_id = ? AND gm.group_id = ?',
            [$tenantId, $groupId],
        ), 'user_id'));
    }

    /** @return list<int> the parents and guardians of a class's learners */
    public function groupGuardianIds(int $tenantId, int $groupId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT DISTINCT gl.guardian_id FROM guardian_links gl
             JOIN learner_group_members gm ON gm.tenant_id = gl.tenant_id AND gm.user_id = gl.learner_id AND gm.role = 'learner'
             JOIN memberships ms ON ms.tenant_id = gl.tenant_id AND ms.user_id = gl.guardian_id AND ms.status = 'active'
             WHERE gl.tenant_id = ? AND gm.group_id = ? AND gl.status = 'active'",
            [$tenantId, $groupId],
        ), 'guardian_id'));
    }

    /**
     * School members with these email addresses.
     *
     * @param list<string> $emails
     * @return array<string, int> email => user id
     */
    public function membersByEmail(int $tenantId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }
        $out = [];
        foreach (
            $this->db->fetchAll(
                "SELECT u.id, LOWER(u.email) AS email FROM users u
                 JOIN memberships ms ON ms.user_id = u.id AND ms.tenant_id = ? AND ms.status = 'active'
                 WHERE LOWER(u.email) IN (" . implode(',', array_fill(0, count($emails), '?')) . ')',
                array_merge([$tenantId], $emails),
            ) as $row
        ) {
            $out[(string) $row['email']] = (int) $row['id'];
        }

        return $out;
    }
}
