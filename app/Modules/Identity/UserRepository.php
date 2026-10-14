<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Clock;
use App\Core\Database\Connection;

final class UserRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);

        return $row === null ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE email = ?', [self::normaliseEmail($email)]);

        return $row === null ? null : User::fromRow($row);
    }

    public function passwordHashFor(int $userId): ?string
    {
        $hash = $this->db->fetchValue('SELECT password_hash FROM users WHERE id = ?', [$userId]);

        return is_string($hash) ? $hash : null;
    }

    public function create(
        string $email,
        string $displayName,
        string $passwordHash,
        bool $platformAdmin = false,
        bool $mustChangePassword = false,
    ): User {
        $now = $this->clock->sqlNow();
        $email = self::normaliseEmail($email);
        $id = $this->db->insert(
            'INSERT INTO users (email, display_name, password_hash, status, is_platform_admin, session_version,
                                password_changed_at, must_change_password, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [$email, $displayName, $passwordHash, User::STATUS_ACTIVE, $platformAdmin ? 1 : 0, $now, $mustChangePassword ? 1 : 0, $now, $now],
        );

        return new User($id, $email, $displayName, User::STATUS_ACTIVE, $platformAdmin, 1, $mustChangePassword);
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        $this->db->execute(
            'UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?',
            [$displayName, $this->clock->sqlNow(), $userId],
        );
    }

    /** Changing the password bumps the session version, signing out every other session (IAM-006). */
    public function updatePassword(int $userId, string $passwordHash): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE users SET password_hash = ?, password_changed_at = ?, session_version = session_version + 1,
                              must_change_password = 0, updated_at = ? WHERE id = ?',
            [$passwordHash, $now, $now, $userId],
        );
    }

    /** Silent rehash after a successful login does not end other sessions. */
    public function rehashPassword(int $userId, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
            [$passwordHash, $this->clock->sqlNow(), $userId],
        );
    }

    /** Signs the user out of every session on their next request. */
    public function endAllSessions(int $userId): void
    {
        $this->db->execute(
            'UPDATE users SET session_version = session_version + 1, updated_at = ? WHERE id = ?',
            [$this->clock->sqlNow(), $userId],
        );
    }

    public function setAppearance(int $userId, string $appearance): void
    {
        if (!isset(User::APPEARANCES[$appearance])) {
            throw new \InvalidArgumentException('Unknown appearance');
        }
        $this->db->execute('UPDATE users SET appearance = ?, updated_at = ? WHERE id = ?', [$appearance, $this->clock->sqlNow(), $userId]);
    }

    public function markEmailVerified(int $userId): void
    {
        $this->db->execute('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [$this->clock->sqlNow(), $this->clock->sqlNow(), $userId]);
    }

    /** A person who signs themselves up: their own password, email not yet confirmed. */
    public function register(string $email, string $displayName, string $passwordHash, bool $emailConfirmed): User
    {
        $now = $this->clock->sqlNow();
        $email = self::normaliseEmail($email);
        $id = $this->db->insert(
            'INSERT INTO users (email, display_name, password_hash, status, is_platform_admin, session_version,
                                password_changed_at, must_change_password, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, 1, ?, 0, ?, ?, ?)',
            [$email, $displayName, $passwordHash, User::STATUS_ACTIVE, $now, $emailConfirmed ? $now : null, $now, $now],
        );

        return $this->findById($id) ?? throw new \RuntimeException('Account not saved');
    }

    public function setStatus(int $userId, string $status): void
    {
        $this->db->execute(
            'UPDATE users SET status = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
            [$status, $this->clock->sqlNow(), $userId],
        );
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
