<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs;

/** Reading what learners type: "0,25", " 12.5 Ω", "1 200" all mean what they look like. */
final class Answers
{
    public static function number(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $clean = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($value));
        $clean = (string) preg_replace('/[^0-9.\-+eE]+$/u', '', $clean);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    /** True when $value is within $percent of $target (absolute $floor for targets near zero). */
    public static function close(?float $value, float $target, float $percent, float $floor = 0.0): bool
    {
        if ($value === null) {
            return false;
        }

        return abs($value - $target) <= max(abs($target) * $percent / 100, $floor);
    }

    /** Formats a number for feedback without trailing zeros. */
    public static function show(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
