<?php

declare(strict_types=1);

namespace App\Core\Session;

/** In-memory session for tests and CLI. Tracks regeneration so tests can assert it. */
final class ArraySession implements Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    public int $regenerations = 0;

    public function start(): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerations++;
    }

    public function flash(string $key, string $message): void
    {
        $flash = $this->data['_flash'] ?? [];
        $flash = is_array($flash) ? $flash : [];
        $flash[$key] = $message;
        $this->data['_flash'] = $flash;
    }

    public function pullFlash(string $key): ?string
    {
        $flash = $this->data['_flash'] ?? [];
        if (!is_array($flash) || !isset($flash[$key])) {
            return null;
        }
        $value = $flash[$key];
        unset($flash[$key]);
        $this->data['_flash'] = $flash;

        return is_string($value) ? $value : null;
    }
}
