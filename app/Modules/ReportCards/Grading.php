<?php

declare(strict_types=1);

namespace App\Modules\ReportCards;

/**
 * Grading scales for report cards (ADR 0046). "waec" follows the nine grades that
 * West African secondary schools use (A1 to F9); "simple" is A to F. A school
 * picks one per report card.
 */
final class Grading
{
    public const SCALES = [
        'waec' => 'A1 to F9 (75 and above is A1, below 40 is F9)',
        'simple' => 'A to F (70 and above is A, below 40 is F)',
    ];

    /** @var array<string, list<array{0: int, 1: string, 2: string}>> lowest score, grade, word */
    private const BANDS = [
        'waec' => [
            [75, 'A1', 'Excellent'], [70, 'B2', 'Very good'], [65, 'B3', 'Good'], [60, 'C4', 'Credit'], [55, 'C5', 'Credit'],
            [50, 'C6', 'Credit'], [45, 'D7', 'Pass'], [40, 'E8', 'Pass'], [0, 'F9', 'Fail'],
        ],
        'simple' => [[70, 'A', 'Excellent'], [60, 'B', 'Very good'], [50, 'C', 'Good'], [45, 'D', 'Fair'], [40, 'E', 'Pass'], [0, 'F', 'Fail']],
    ];

    /** @return array{0: string, 1: string} grade and word for a score out of 100 */
    public static function grade(string $scale, int $score): array
    {
        foreach (self::BANDS[$scale] ?? self::BANDS['waec'] as [$min, $grade, $word]) {
            if ($score >= $min) {
                return [$grade, $word];
            }
        }

        return ['F9', 'Fail'];
    }

    /** @return list<array{0: int, 1: string, 2: string}> the scale's bands, for the key printed on the card */
    public static function bands(string $scale): array
    {
        return self::BANDS[$scale] ?? self::BANDS['waec'];
    }

    /** 1st, 2nd, 3rd, 4th, 11th, 12th, 13th, 21st, 22nd, 101st… */
    public static function ordinal(int $n): string
    {
        $tens = $n % 100;
        $suffix = $tens >= 11 && $tens <= 13 ? 'th' : match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return $n . $suffix;
    }

    /**
     * Positions with ties sharing a place: scores [90, 80, 80, 70] give 1, 2, 2, 4.
     *
     * @param array<int, int|float> $scores by id
     * @return array<int, int> position by id
     */
    public static function positions(array $scores): array
    {
        arsort($scores);
        $out = [];
        $place = 0;
        $seen = 0;
        $last = null;
        foreach ($scores as $id => $score) {
            $seen++;
            if ($last === null || $score < $last) {
                $place = $seen;
                $last = $score;
            }
            $out[$id] = $place;
        }

        return $out;
    }
}
