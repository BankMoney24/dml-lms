<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Source of "now" in UTC, replaceable in tests. */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function sqlNow(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }
}
