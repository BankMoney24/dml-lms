<?php

declare(strict_types=1);

namespace App\Modules\Api;

/** A key a school made for its own systems to read data through API v1 (ADR 0036). */
final class ApiKey
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly string $prefix,
        public readonly string $createdByName,
        public readonly string $createdAt,
        public readonly ?string $lastUsedAt,
        public readonly ?string $revokedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['tenant_id'],
            (string) $r['name'],
            (string) $r['prefix'],
            (string) ($r['creator_name'] ?? ''),
            (string) $r['created_at'],
            isset($r['last_used_at']) ? (string) $r['last_used_at'] : null,
            isset($r['revoked_at']) ? (string) $r['revoked_at'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    /** What people see to tell keys apart: "dmlk_ab12cd34_…". */
    public function hint(): string
    {
        return ApiKeys::PREFIX . $this->prefix . '_…';
    }
}
