<?php

declare(strict_types=1);

namespace App\Core\Session;

/**
 * Server-side PHP session with hardened cookie settings. Session files are
 * stored in the application's private storage, not the shared system tmp,
 * because the server hosts other sites.
 */
final class NativeSession implements Session
{
    public function __construct(
        private readonly string $savePath,
        private readonly bool $secureCookie,
        private readonly int $idleLifetimeSeconds = 7200,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0700, true);
        }
        session_save_path($this->savePath);
        session_name($this->secureCookie ? '__Host-dml_session' : 'dml_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->idleLifetimeSeconds);
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        session_start();

        $now = time();
        $last = $_SESSION['_last_seen'] ?? null;
        if (is_int($last) && $now - $last > $this->idleLifetimeSeconds) {
            $this->invalidate();
        }
        $_SESSION['_last_seen'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_last_seen'] = time();
    }

    public function flash(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    public function pullFlash(string $key): ?string
    {
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return is_string($value) ? $value : null;
    }
}
