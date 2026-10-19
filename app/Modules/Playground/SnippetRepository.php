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

    /** @return list<array{id: int, title: string, language: string, updated_at: string, lines: int}> */
    public function list(int $tenantId, int $userId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'language' => (string) $r['language'],
            'updated_at' => (string) $r['updated_at'],
            'lines' => (int) $r['line_count'],
        ], $this->db->fetchAll(
            "SELECT id, title, language, updated_at, (LENGTH(code) - LENGTH(REPLACE(code, '\\n', '')) + 1) AS line_count
             FROM code_snippets WHERE tenant_id = ? AND user_id = ? ORDER BY updated_at DESC, id DESC LIMIT " . self::MAX_PER_PERSON,
            [$tenantId, $userId],
        ));
    }

    /** @return ?array{id: int, title: string, language: string, code: string} */
    public function find(int $tenantId, int $userId, int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT id, title, language, code FROM code_snippets WHERE tenant_id = ? AND user_id = ? AND id = ?', [$tenantId, $userId, $id]);

        return $row === null ? null : ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'language' => (string) $row['language'], 'code' => (string) $row['code']];
    }

    public function count(int $tenantId, int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM code_snippets WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
    }

    public function create(int $tenantId, int $userId, string $title, string $code, string $language = 'javascript'): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO code_snippets (tenant_id, user_id, title, language, code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $title, $language, $code, $now, $now],
        );
    }

    public function update(int $tenantId, int $userId, int $id, string $title, string $code, string $language = 'javascript'): bool
    {
        return $this->db->execute(
            'UPDATE code_snippets SET title = ?, language = ?, code = ?, updated_at = ? WHERE tenant_id = ? AND user_id = ? AND id = ?',
            [$title, $language, $code, $this->clock->sqlNow(), $tenantId, $userId, $id],
        ) > 0;
    }

    /** @return array<string, string> challenge key => language it was last passed in */
    public function solved(int $tenantId, int $userId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT challenge, language FROM code_challenge_solves WHERE tenant_id = ? AND user_id = ? ORDER BY solved_at', [$tenantId, $userId]) as $r) {
            $out[(string) $r['challenge']] = (string) $r['language'];
        }

        return $out;
    }

    /** @return ?array{code: string, language: string} the code last passed for this challenge */
    public function solution(int $tenantId, int $userId, string $challenge, string $language): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT code, language FROM code_challenge_solves WHERE tenant_id = ? AND user_id = ? AND challenge = ? AND language = ?',
            [$tenantId, $userId, $challenge, $language],
        );

        return $row === null ? null : ['code' => (string) $row['code'], 'language' => (string) $row['language']];
    }

    public function recordSolve(int $tenantId, int $userId, string $challenge, string $language, string $code): void
    {
        $this->db->execute(
            'INSERT INTO code_challenge_solves (tenant_id, user_id, challenge, language, code, solved_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE code = VALUES(code), solved_at = VALUES(solved_at)',
            [$tenantId, $userId, $challenge, $language, $code, $this->clock->sqlNow()],
        );
    }

    public function delete(int $tenantId, int $userId, int $id): void
    {
        $this->db->execute('DELETE FROM code_snippets WHERE tenant_id = ? AND user_id = ? AND id = ?', [$tenantId, $userId, $id]);
    }
}
