<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Classroom;

/**
 * Where a school's online classroom runs: its own Jitsi Meet server, set up
 * for token sign-in (ADR 0029). The signing secret is not here: it is read
 * only when a pass is made, and never shown.
 */
final class ClassroomSettings
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $domain,
        public readonly string $appId,
        public readonly bool $lowData,
        public readonly string $updatedAt,
        public readonly string $updatedBy = '',
    ) {
    }

    /** A plain host name such as live.example.org: no scheme, port, path or anything that could break a header. */
    public static function isHostName(string $domain): bool
    {
        return strlen($domain) <= 190
            && preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1;
    }

    public function origin(): string
    {
        return 'https://' . $this->domain;
    }
}
