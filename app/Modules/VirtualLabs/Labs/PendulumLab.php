<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Answers;
use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Physics: the simple pendulum (ADR 0035), as in the SS2 practical. Time 20
 * oscillations at several lengths, tabulate T and T², plot T² against l, and
 * find g from the slope (g = 4π² ÷ slope). The local value of g differs a
 * little each session, as it does from place to place.
 */
final class PendulumLab implements Lab
{
    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'pendulum',
            version: 1,
            title: 'Simple pendulum: measure g',
            subject: 'Physics',
            track: 'school',
            level: 'SS2',
            summary: 'Time 20 swings of a pendulum at different lengths, plot T² against length, and work out the acceleration due to gravity from the slope.',
            objective: 'Determine the acceleration due to gravity, g, from the relationship T = 2π√(l/g).',
            tasks: [
                'Set a length, start the swing and time 20 complete oscillations. Record the time.',
                'Repeat for at least five different lengths between 20 cm and 120 cm.',
                'Work out T (time ÷ 20) and T² for each length. The graph plots T² against l (in metres).',
                'Find the slope of the line and calculate g = 4π² ÷ slope.',
            ],
            prerequisites: 'Using a stopwatch; plotting a straight-line graph and finding its slope.',
            minutes: 35,
        );
    }

    public function params(Seed $seed): array
    {
        return [
            'public' => ['lq' => $seed->pick([45, 55, 65, 75, 85, 95]), 'g' => $seed->int(9760, 9830) / 1000],
            'secret' => [],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('slope', 'Slope of your T² against l graph', 'number', 's²/m', 'To 2 decimal places.'),
            new LabField('g', 'Acceleration due to gravity, g', 'number', 'm/s²', 'To 2 decimal places.'),
            new LabField('period', sprintf('Period of a pendulum %d cm long', (int) $public['lq']), 'number', 's', 'Use your value of g, or read it from your graph.'),
            new LabField('readings', 'Your readings', 'hidden'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $g = (float) $params['public']['g'];
        $slope = 4 * M_PI * M_PI / $g;
        $lq = (int) $params['public']['lq'];
        $period = 2 * M_PI * sqrt($lq / 100 / $g);
        $readings = self::readings((string) ($answers['readings'] ?? ''));
        $lengths = count(array_unique(array_map(static fn (array $r): int => (int) $r[0], $readings)));
        $gAns = Answers::number($answers['g'] ?? null);
        $sAns = Answers::number($answers['slope'] ?? null);
        $pAns = Answers::number($answers['period'] ?? null);

        return LabResult::fromParts([
            ['label' => 'Slope', 'ok' => Answers::close($sAns, $slope, 4), 'marks' => 25,
                'detail' => sprintf('The slope is about %s s²/m (T² = (4π²/g) × l).', Answers::show($slope, 2))],
            ['label' => 'g', 'ok' => Answers::close($gAns, $g, 3), 'marks' => 35,
                'detail' => sprintf('g here is about %s m/s². g = 4π² ÷ slope.', Answers::show($g, 2))],
            ['label' => 'Predicted period', 'ok' => Answers::close($pAns, $period, 3), 'marks' => 20,
                'detail' => sprintf('T = 2π√(%s ÷ %s) = %s s.', Answers::show($lq / 100, 2), Answers::show($g, 2), Answers::show($period, 2))],
            ['label' => 'Readings', 'ok' => $lengths >= 5, 'marks' => 20,
                'detail' => $lengths >= 5 ? sprintf('You timed %d different lengths.', $lengths) : 'Time at least five different lengths: a line needs several points.'],
        ], ['slope' => $answers['slope'] ?? '', 'g' => $answers['g'] ?? '', 'period' => $answers['period'] ?? '', 'readings' => $readings]);
    }

    /** @return list<array{0: float, 1: float}> [length in cm, time for 20 oscillations in s] */
    private static function readings(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) ? array_slice($data, 0, 20) : [] as $row) {
            if (is_array($row) && is_numeric($row[0] ?? null) && is_numeric($row[1] ?? null)) {
                $out[] = [(float) $row[0], (float) $row[1]];
            }
        }

        return $out;
    }
}
