<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Classroom;

/**
 * A JSON Web Token (HS256) in the shape Jitsi Meet's token authentication
 * (prosody "token" auth, jitsi-meet-tokens) expects:
 *   iss = the app id, aud = "jitsi", sub = the Jitsi domain, room = the room,
 *   nbf/exp = when it may be used, context.user = who is joining.
 * Signed on the server with the school's secret; the secret never leaves it.
 */
final class JitsiToken
{
    /**
     * @param array{id: string, name: string, moderator: bool} $user
     */
    public static function make(ClassroomSettings $settings, string $secret, string $room, array $user, int $notBefore, int $expires): string
    {
        $claims = [
            'aud' => 'jitsi',
            'iss' => $settings->appId,
            'sub' => $settings->domain,
            'room' => $room,
            'iat' => $notBefore,
            'nbf' => $notBefore,
            'exp' => $expires,
            // Read by mod_token_affiliation (and Jitsi as a Service): teachers
            // run the room, learners join as ordinary participants.
            'moderator' => $user['moderator'],
            'context' => [
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'moderator' => $user['moderator'] ? 'true' : 'false',
                    'affiliation' => $user['moderator'] ? 'owner' : 'member',
                ],
                'features' => [
                    'recording' => false,
                    'livestreaming' => false,
                    'screen-sharing' => true,
                ],
            ],
        ];

        return self::sign($claims, $secret);
    }

    /** @param array<string, mixed> $claims */
    public static function sign(array $claims, string $secret): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = self::b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Reads a token back if the signature matches (for tests and diagnostics).
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true));
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($claims) ? $claims : null;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
