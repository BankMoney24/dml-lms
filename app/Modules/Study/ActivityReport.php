<?php

declare(strict_types=1);

namespace App\Modules\Study;

use App\Core\Clock;
use App\Core\Database\Connection;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Which days a learner did something: opened or finished a lesson, tried a
 * quiz, ran a lab, handed in work or wrote a note. Days are the school's
 * local days. Only counts are kept; nothing here says what was done.
 */
final class ActivityReport
{
    public const WEEKS = 16;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return array{
     *   weeks: list<list<array{date: string, count: int, level: int, future: bool}>>,
     *   months: list<array{column: int, label: string}>,
     *   streak: int, today: bool, active_days: int, this_week: int, best_streak: int
     * }
     */
    public function for(int $tenantId, int $userId, string $timezone, int $weeks = self::WEEKS): array
    {
        $zone = new DateTimeZone($timezone);
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        // Columns are Monday-to-Sunday weeks; the last column holds today.
        $start = $today->modify('monday this week')->sub(new DateInterval('P' . (7 * ($weeks - 1)) . 'D'));
        $since = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $counts = [];
        foreach ($this->timestamps($tenantId, $userId, $since) as $utc) {
            $day = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        $todayKey = $today->format('Y-m-d');
        $columns = [];
        $months = [];
        $lastMonth = '';
        for ($w = 0; $w < $weeks; $w++) {
            $column = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $start->add(new DateInterval('P' . ($w * 7 + $d) . 'D'));
                $key = $date->format('Y-m-d');
                $n = $counts[$key] ?? 0;
                $column[] = ['date' => $key, 'count' => $n, 'level' => self::level($n), 'future' => $key > $todayKey];
                if ($d === 0 && $date->format('M') !== $lastMonth) {
                    $lastMonth = $date->format('M');
                    $months[] = ['column' => $w, 'label' => $lastMonth];
                }
            }
            $columns[] = $column;
        }

        // A month label needs room: when two would sit closer than three columns, keep the later one.
        $months = array_values(array_filter(
            $months,
            static fn (array $m, int $i): bool => !isset($months[$i + 1]) || $months[$i + 1]['column'] - $m['column'] >= 3,
            ARRAY_FILTER_USE_BOTH,
        ));

        // The streak counts back from today, or from yesterday while today is still open.
        $streak = 0;
        $day = isset($counts[$todayKey]) ? $today : $today->sub(new DateInterval('P1D'));
        while (isset($counts[$day->format('Y-m-d')])) {
            $streak++;
            $day = $day->sub(new DateInterval('P1D'));
        }

        $best = 0;
        $run = 0;
        foreach ($columns as $column) {
            foreach ($column as $cell) {
                if ($cell['future']) {
                    continue;
                }
                $run = $cell['count'] > 0 ? $run + 1 : 0;
                $best = max($best, $run);
            }
        }

        $weekStart = $today->modify('monday this week')->format('Y-m-d');

        return [
            'weeks' => $columns,
            'months' => $months,
            'streak' => $streak,
            'today' => isset($counts[$todayKey]),
            'active_days' => count($counts),
            'this_week' => count(array_filter(array_keys($counts), static fn ($k): bool => (string) $k >= $weekStart && (string) $k <= $todayKey)),
            'best_streak' => $best,
        ];
    }

    private static function level(int $n): int
    {
        return match (true) {
            $n === 0 => 0,
            $n === 1 => 1,
            $n <= 3 => 2,
            $n <= 6 => 3,
            default => 4,
        };
    }

    /** @return list<string> UTC timestamps */
    private function timestamps(int $tenantId, int $userId, string $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT lp.updated_at AS t FROM lesson_progress lp
               JOIN enrolments e ON e.tenant_id = lp.tenant_id AND e.id = lp.enrolment_id
              WHERE lp.tenant_id = ? AND e.user_id = ? AND lp.updated_at >= ?
             UNION ALL SELECT submitted_at FROM quiz_attempts WHERE tenant_id = ? AND user_id = ? AND submitted_at >= ?
             UNION ALL SELECT started_at FROM lab_sessions WHERE tenant_id = ? AND user_id = ? AND started_at >= ?
             UNION ALL SELECT submitted_at FROM assignment_submissions WHERE tenant_id = ? AND user_id = ? AND submitted_at >= ?
             UNION ALL SELECT updated_at FROM lesson_notes WHERE tenant_id = ? AND user_id = ? AND updated_at >= ?',
            [$tenantId, $userId, $since, $tenantId, $userId, $since, $tenantId, $userId, $since, $tenantId, $userId, $since, $tenantId, $userId, $since],
        );

        return array_map(static fn (array $r): string => (string) $r['t'], $rows);
    }
}
