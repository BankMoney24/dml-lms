<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Database\Connection;

final class NotificationRepository
{
    /** Read notifications older than this are removed when the person opens the list. */
    public const KEEP_READ_DAYS = 90;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function add(int $tenantId, int $userId, string $kind, string $title, ?string $body, ?string $link): void
    {
        // Only current members of the school receive anything.
        $this->db->execute(
            "INSERT INTO notifications (tenant_id, user_id, kind, title, body, link, created_at)
             SELECT m.tenant_id, m.user_id, ?, ?, ?, ?, ? FROM memberships m WHERE m.tenant_id = ? AND m.user_id = ? AND m.status = 'active'",
            [$kind, $title, $body, $link, $this->clock->sqlNow(), $tenantId, $userId],
        );
    }

    public function unreadCount(int $tenantId, int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM (SELECT 1 FROM notifications WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL LIMIT 100) n',
            [$tenantId, $userId],
        );
    }

    /** @return list<Notification> newest first */
    public function recent(int $tenantId, int $userId, int $limit = 50): array
    {
        return array_map(
            static fn (array $r): Notification => Notification::fromRow($r),
            $this->db->fetchAll(
                'SELECT * FROM notifications WHERE tenant_id = ? AND user_id = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, 200)),
                [$tenantId, $userId],
            ),
        );
    }

    public function find(int $tenantId, int $userId, int $id): ?Notification
    {
        $row = $this->db->fetchOne('SELECT * FROM notifications WHERE tenant_id = ? AND user_id = ? AND id = ?', [$tenantId, $userId, $id]);

        return $row === null ? null : Notification::fromRow($row);
    }

    public function markRead(int $tenantId, int $userId, int $id): void
    {
        $this->db->execute(
            'UPDATE notifications SET read_at = ? WHERE tenant_id = ? AND user_id = ? AND id = ? AND read_at IS NULL',
            [$this->clock->sqlNow(), $tenantId, $userId, $id],
        );
    }

    public function markAllRead(int $tenantId, int $userId): int
    {
        return $this->db->execute(
            'UPDATE notifications SET read_at = ? WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL',
            [$this->clock->sqlNow(), $tenantId, $userId],
        );
    }

    public function pruneRead(int $tenantId, int $userId): void
    {
        $this->db->execute(
            'DELETE FROM notifications WHERE tenant_id = ? AND user_id = ? AND read_at IS NOT NULL AND read_at < ?',
            [$tenantId, $userId, $this->clock->now()->modify('-' . self::KEEP_READ_DAYS . ' days')->format('Y-m-d H:i:s')],
        );
    }
}
