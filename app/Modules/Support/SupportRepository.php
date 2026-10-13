<?php

declare(strict_types=1);

namespace App\Modules\Support;

use App\Core\Clock;
use App\Core\Database\Connection;

final class SupportRepository
{
    private const SELECT = 'SELECT r.*, u.display_name, u.email FROM support_requests r JOIN users u ON u.id = r.user_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $userId, string $category, string $subject): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO support_requests (tenant_id, user_id, category, subject, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $category, $subject, SupportRequest::OPEN, $now, $now],
        );
    }

    public function addMessage(int $tenantId, int $requestId, int $authorId, bool $fromStaff, string $body): int
    {
        return $this->db->insert(
            'INSERT INTO support_messages (tenant_id, request_id, author_id, from_staff, body, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $requestId, $authorId, (int) $fromStaff, $body, $this->clock->sqlNow()],
        );
    }

    public function find(int $tenantId, int $id, bool $lock = false): ?SupportRequest
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE r.tenant_id = ? AND r.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);

        return $row === null ? null : SupportRequest::fromRow($row);
    }

    public function setStatus(int $tenantId, int $id, string $status): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE support_requests SET status = ?, updated_at = ?, resolved_at = ? WHERE tenant_id = ? AND id = ?',
            [$status, $now, $status === SupportRequest::RESOLVED ? $now : null, $tenantId, $id],
        );
    }

    /** @return list<SupportRequest> newest activity first */
    public function forUser(int $tenantId, int $userId): array
    {
        return $this->many(self::SELECT . ' WHERE r.tenant_id = ? AND r.user_id = ? ORDER BY r.updated_at DESC, r.id DESC LIMIT 50', [$tenantId, $userId]);
    }

    /** @return list<SupportRequest> */
    public function queue(int $tenantId, string $status, int $limit = 200): array
    {
        $order = $status === SupportRequest::RESOLVED ? 'r.updated_at DESC' : 'r.updated_at ASC';

        return $this->many(self::SELECT . ' WHERE r.tenant_id = ? AND r.status = ? ORDER BY ' . $order . ', r.id LIMIT ' . max(1, min($limit, 500)), [$tenantId, $status]);
    }

    /** @return array<string, int> status => count */
    public function counts(int $tenantId): array
    {
        $out = [SupportRequest::OPEN => 0, SupportRequest::ANSWERED => 0, SupportRequest::RESOLVED => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM support_requests WHERE tenant_id = ? GROUP BY status', [$tenantId]) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    public function openCountFor(int $tenantId, int $userId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM support_requests WHERE tenant_id = ? AND user_id = ? AND status <> 'resolved'", [$tenantId, $userId]);
    }

    /** @return list<array{id: int, author: string, author_id: ?int, from_staff: bool, body: string, created_at: string}> oldest first */
    public function messages(int $tenantId, int $requestId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'author' => (string) ($r['display_name'] ?? 'Former member'),
            'author_id' => $r['author_id'] === null ? null : (int) $r['author_id'],
            'from_staff' => (bool) $r['from_staff'],
            'body' => (string) $r['body'],
            'created_at' => (string) $r['created_at'],
        ], $this->db->fetchAll(
            'SELECT m.*, u.display_name FROM support_messages m LEFT JOIN users u ON u.id = m.author_id WHERE m.tenant_id = ? AND m.request_id = ? ORDER BY m.id LIMIT 500',
            [$tenantId, $requestId],
        ));
    }

    /** The request a help message belongs to, for attachment downloads. */
    public function requestOfMessage(int $tenantId, int $messageId): ?SupportRequest
    {
        $row = $this->db->fetchOne(
            self::SELECT . ' JOIN support_messages m ON m.tenant_id = r.tenant_id AND m.request_id = r.id WHERE r.tenant_id = ? AND m.id = ?',
            [$tenantId, $messageId],
        );

        return $row === null ? null : SupportRequest::fromRow($row);
    }

    /** @return list<int> people who answer help requests in this school */
    public function staffIds(int $tenantId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT user_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND role IN ('owner', 'admin') LIMIT 50",
            [$tenantId],
        ), 'user_id'));
    }

    /**
     * @param list<mixed> $params
     * @return list<SupportRequest>
     */
    private function many(string $sql, array $params): array
    {
        return array_map(static fn (array $r): SupportRequest => SupportRequest::fromRow($r), $this->db->fetchAll($sql, $params));
    }
}
