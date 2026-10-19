<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;

/** The clock the sample-data seeder moves through time (ADR 0027); null means the real now. */
final class SampleClock extends Clock
{
    public ?DateTimeImmutable $now = null;

    public function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function at(DateTimeImmutable $when): void
    {
        $this->now = $when;
    }

    public function shift(int $seconds): void
    {
        $this->now = $this->now()->modify('+' . $seconds . ' seconds');
    }
}
