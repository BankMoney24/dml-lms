<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\Clock;
use App\Core\Database\Connection;

final class ApiKeyRepository
{
    private const SELECT = 'SELECT k.*, u.display_name AS creator_name FROM api_keys k JOIN users u ON u.id = k.created_by';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, string $name, string $prefix, string $hash, int $createdBy): int
    {
        return $this->db->insert(
            'INSERT INTO api_keys (tenant_id, name, prefix, key_hash, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $name, $prefix, $hash, $createdBy, $this->clock->sqlNow()],
        );
    }

    /** @return list<ApiKey> active keys first, newest first */
    public function all(int $tenantId): array
    {
        return array_map(ApiKey::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE k.tenant_id = ? ORDER BY k.revoked_at IS NULL DESC, k.id DESC LIMIT 100',
            [$tenantId],
        ));
    }

    public function find(int $tenantId, int $id): ?ApiKey
    {
        $r = $this->db->fetchOne(self::SELECT . ' WHERE k.tenant_id = ? AND k.id = ?', [$tenantId, $id]);

        return $r === null ? null : ApiKey::fromRow($r);
    }

    public function findActiveByHash(string $hash): ?ApiKey
    {
        $r = $this->db->fetchOne(self::SELECT . ' WHERE k.key_hash = ? AND k.revoked_at IS NULL', [$hash]);

        return $r === null ? null : ApiKey::fromRow($r);
    }

    public function activeCount(int $tenantId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM api_keys WHERE tenant_id = ? AND revoked_at IS NULL', [$tenantId]);
    }

    public function revoke(int $tenantId, int $id): void
    {
        $this->db->execute('UPDATE api_keys SET revoked_at = ? WHERE tenant_id = ? AND id = ? AND revoked_at IS NULL', [$this->clock->sqlNow(), $tenantId, $id]);
    }

    /** Records use at most once every five minutes, so busy integrations do not write on every request. */
    public function touch(ApiKey $key): void
    {
        $now = $this->clock->now();
        if ($key->lastUsedAt !== null && $key->lastUsedAt > $now->modify('-5 minutes')->format('Y-m-d H:i:s')) {
            return;
        }
        $this->db->execute('UPDATE api_keys SET last_used_at = ? WHERE tenant_id = ? AND id = ?', [$now->format('Y-m-d H:i:s'), $key->tenantId, $key->id]);
    }
}
