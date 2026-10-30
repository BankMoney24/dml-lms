<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

/**
 * Friendly made-up names for players, when the teacher would rather nobody
 * types their own (ADR 0042): an adjective and a West African animal or thing,
 * such as "Swift Hornbill".
 */
final class FunNames
{
    private const FIRST = ['Swift', 'Clever', 'Brave', 'Bright', 'Calm', 'Bold', 'Happy', 'Quick', 'Sharp', 'Kind', 'Lucky', 'Mighty', 'Noble', 'Steady', 'Sunny', 'Wise', 'Gentle',
        'Proud', 'Keen', 'Cool'];
    private const SECOND = ['Hornbill', 'Leopard', 'Baobab', 'Pangolin', 'Gazelle', 'Heron', 'Tortoise', 'Kingfisher', 'Lion', 'Elephant', 'Weaver', 'Antelope', 'Falcon', 'Mango',
        'Giraffe', 'Parrot', 'Cheetah', 'Okapi', 'Iroko', 'Egret'];

    /** @param callable(string): bool $taken */
    public static function pick(callable $taken): string
    {
        for ($i = 0; $i < 40; $i++) {
            $name = self::FIRST[random_int(0, count(self::FIRST) - 1)] . ' ' . self::SECOND[random_int(0, count(self::SECOND) - 1)];
            if (!$taken($name)) {
                return $name;
            }
        }
        do {
            $name = self::FIRST[random_int(0, count(self::FIRST) - 1)] . ' ' . self::SECOND[random_int(0, count(self::SECOND) - 1)] . ' ' . random_int(2, 99);
        } while ($taken($name));

        return $name;
    }
}
