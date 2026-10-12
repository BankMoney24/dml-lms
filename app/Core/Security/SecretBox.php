<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

/**
 * Authenticated encryption for small secrets at rest (AES-256-GCM, key
 * derived from APP_KEY). Changing APP_KEY makes existing values unreadable,
 * which for two-step secrets means users set them up again.
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    private readonly string $key;

    public function __construct(string $appKey)
    {
        $this->key = hash_hmac('sha256', 'dml-secretbox-v1', $appKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }
}
