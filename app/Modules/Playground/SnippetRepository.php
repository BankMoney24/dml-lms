<?php

declare(strict_types=1);

namespace App\Modules\Playground;

use App\Core\Clock;
use App\Core\Database\Connection;

/** A member's saved playground code. Read only for the person who wrote it. */
final class SnippetRepository
{
    public const MAX_PER_PERSON = 50;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @return list<array{id: int, title: string, updated_at: string, lines: int}> */
    public function list(int $tenantId, int $userId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'updated_at' => (string) $r['updated_at'],
            'lines' => (int) $r['line_count'],
        ], $this->db->fetchAll(
            "SELECT id, title, updated_at, (LENGTH(code) - LENGTH(REPLACE(code, '\\n', '')) + 1) AS line_count
             FROM code_snippets WHERE tenant_id = ? AND user_id = ? ORDER BY updated_at DESC, id DESC LIMIT " . self::MAX_PER_PERSON,
            [$tenantId, $userId],
        ));
    }

    /** @return ?array{id: int, title: string, code: string} */
    public function find(int $tenantId, int $userId, int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT id, title, code FROM code_snippets WHERE tenant_id = ? AND user_id = ? AND id = ?', [$tenantId, $userId, $id]);

        return $row === null ? null : ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'code' => (string) $row['code']];
    }

    public function count(int $tenantId, int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM code_snippets WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
    }

    public function create(int $tenantId, int $userId, string $title, string $code): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO code_snippets (tenant_id, user_id, title, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $title, $code, $now, $now],
        );
    }

    public function update(int $tenantId, int $userId, int $id, string $title, string $code): bool
    {
        return $this->db->execute(
            'UPDATE code_snippets SET title = ?, code = ?, updated_at = ? WHERE tenant_id = ? AND user_id = ? AND id = ?',
            [$title, $code, $this->clock->sqlNow(), $tenantId, $userId, $id],
        ) > 0;
    }

    public function delete(int $tenantId, int $userId, int $id): void
    {
        $this->db->execute('DELETE FROM code_snippets WHERE tenant_id = ? AND user_id = ? AND id = ?', [$tenantId, $userId, $id]);
    }
}
