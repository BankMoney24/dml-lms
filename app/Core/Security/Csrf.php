<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Session\Session;

/** Synchroniser-token CSRF defence for cookie-authenticated form posts. */
final class Csrf
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $submitted): bool
    {
        $expected = $this->session->get(self::KEY);

        return is_string($expected) && is_string($submitted) && hash_equals($expected, $submitted);
    }

    /** Call after login/logout so a pre-auth token cannot be replayed. */
    public function rotate(): void
    {
        $this->session->remove(self::KEY);
    }
}
