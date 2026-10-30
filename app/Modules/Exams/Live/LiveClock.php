<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

use DateTimeImmutable;
use DateTimeZone;

/** Millisecond times for live quizzes, stored as DATETIME(3) in UTC. */
final class LiveClock
{
    public static function sql(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public static function ms(string $sql): int
    {
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', str_contains($sql, '.') ? $sql : $sql . '.000', new DateTimeZone('UTC'));
        if ($t === false) {
            return 0;
        }

        return (int) $t->format('U') * 1000 + intdiv((int) $t->format('u'), 1000);
    }

    public static function nowMs(DateTimeImmutable $now): int
    {
        return (int) $now->format('U') * 1000 + intdiv((int) $now->format('u'), 1000);
    }
}
