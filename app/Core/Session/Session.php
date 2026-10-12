<?php

declare(strict_types=1);

namespace App\Core\Session;

interface Session
{
    public function start(): void;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Issues a new session id, keeping data. Call on every privilege change. */
    public function regenerate(): void;

    /** Destroys all data and issues a new id. */
    public function invalidate(): void;

    public function flash(string $key, string $message): void;

    public function pullFlash(string $key): ?string;
}
