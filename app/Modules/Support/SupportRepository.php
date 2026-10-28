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
    public function queue(int $tenantId, string $status, int $limit = 200, string $q = '', ?string $category = null): array
    {
        $order = $status === SupportRequest::RESOLVED ? 'r.updated_at DESC' : 'r.updated_at ASC';
        [$where, $params] = self::filter($q, $category);

        return $this->many(
            self::SELECT . ' WHERE r.tenant_id = ? AND r.status = ?' . $where . ' ORDER BY ' . $order . ', r.id LIMIT ' . max(1, min($limit, 500)),
            [$tenantId, $status, ...$params],
        );
    }

    /** @return array<string, int> status => count, for the same words and kind as the list */
    public function counts(int $tenantId, string $q = '', ?string $category = null): array
    {
        $out = [SupportRequest::OPEN => 0, SupportRequest::ANSWERED => 0, SupportRequest::RESOLVED => 0];
        [$where, $params] = self::filter($q, $category);
        $rows = $this->db->fetchAll(
            'SELECT r.status, COUNT(*) AS n FROM support_requests r JOIN users u ON u.id = r.user_id WHERE r.tenant_id = ?' . $where . ' GROUP BY r.status',
            [$tenantId, ...$params],
        );
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Words match the subject, the person's name or email; the kind is a category key.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function filter(string $q, ?string $category): array
    {
        $where = '';
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (r.subject LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if ($category !== null && isset(SupportRequest::CATEGORIES[$category])) {
            $where .= ' AND r.category = ?';
            $params[] = $category;
        }

        return [$where, $params];
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
