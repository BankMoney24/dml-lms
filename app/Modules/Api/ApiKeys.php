<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Tenancy\Tenant;

/**
 * Making, revoking and checking API keys (ADR 0036). A key is "dmlk_" + an 8-character
 * prefix + "_" + 40 random characters. Only its SHA-256 hash is stored; the key is shown
 * to the administrator once. Keys only read, and only their own school's data.
 */
final class ApiKeys
{
    public const PREFIX = 'dmlk_';
    public const MAX_ACTIVE = 10;
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(private readonly ApiKeyRepository $keys, private readonly AuditLog $audit)
    {
    }

    /** Makes a key and returns it in full. This is the only time it exists outside the caller's system. */
    public function create(Tenant $tenant, CurrentUser $admin, string $name, string $clientIp): string
    {
        $admin->authorise(Permission::SchoolManage);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw ValidationException::single('name', 'Name the key after the system that will use it, for example PrimeERP (2 to 80 characters).');
        }
        if ($this->keys->activeCount($tenant->id) >= self::MAX_ACTIVE) {
            throw ValidationException::single('name', 'This school already has ' . self::MAX_ACTIVE . ' keys. Revoke one you no longer use first.');
        }
        $prefix = self::random(8);
        $key = self::PREFIX . $prefix . '_' . self::random(40);
        $id = $this->keys->create($tenant->id, $name, $prefix, hash('sha256', $key), $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'api.key_created', null, null, ['key' => $id, 'name' => $name], $clientIp);

        return $key;
    }

    public function revoke(Tenant $tenant, CurrentUser $admin, ApiKey $key, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $this->keys->revoke($tenant->id, $key->id);
        $this->audit->record($tenant->id, $admin->user->id, 'api.key_revoked', null, null, ['key' => $key->id, 'name' => $key->name], $clientIp);
    }

    /** The active key for this school that matches the bearer token, or null. */
    public function authenticate(Tenant $tenant, string $token): ?ApiKey
    {
        if (preg_match('/^' . self::PREFIX . '[a-z0-9]{8}_[a-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $key = $this->keys->findActiveByHash(hash('sha256', $token));
        if ($key === null || $key->tenantId !== $tenant->id) {
            return null;
        }
        $this->keys->touch($key);

        return $key;
    }

    private static function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
