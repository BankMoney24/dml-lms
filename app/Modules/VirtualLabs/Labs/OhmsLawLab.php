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
 * Physics, SS2: find the resistance hidden in a box using a voltmeter and an
 * ammeter, then use it to predict a current. The cell has a small internal
 * resistance, so the cell's setting is not the voltage across the resistor:
 * learners have to read the voltmeter, as in a real practical.
 */
final class OhmsLawLab implements Lab
{
    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'ohms-law',
            version: 1,
            title: 'Ohm’s law: the hidden resistor',
            subject: 'Physics',
            track: 'school',
            level: 'SS2',
            summary: 'Use a voltmeter and an ammeter to find the resistance of a resistor you cannot see.',
            objective: 'Verify Ohm’s law and use readings of voltage and current to find an unknown resistance.',
            tasks: [
                'Set the supply to several different voltages and record at least three pairs of readings (voltage across the resistor, current).',
                'Work out the resistance R = V ÷ I from your readings (or from the gradient of your V–I graph).',
                'Use your resistance to predict the current for the voltage given in the question.',
            ],
            prerequisites: 'Current, voltage and resistance; reading meters.',
            minutes: 30,
        );
    }

    public function params(Seed $seed): array
    {
        return [
            'public' => [
                'rx' => $seed->int(10, 100),
                'r_int' => $seed->int(5, 15) / 10,
                'vq' => $seed->int(3, 9),
                'max_volts' => 12,
            ],
            'secret' => [],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('resistance', 'Resistance of the hidden resistor', 'number', 'Ω', 'To at least 1 decimal place.'),
            new LabField('current', sprintf('Current through it when the voltage across it is %d V', (int) $public['vq']), 'number', 'mA'),
            new LabField('readings', 'Your readings', 'hidden'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $p = $params['public'];
        $rx = (float) $p['rx'];
        $expectedCurrent = (float) $p['vq'] / $rx * 1000;
        $resistance = Answers::number($answers['resistance'] ?? null);
        $current = Answers::number($answers['current'] ?? null);
        $readings = self::readings($answers['readings'] ?? '');
        $distinct = count(array_unique(array_map(static fn (array $r): string => (string) round($r[0], 2), $readings)));

        return LabResult::fromParts([
            [
                'label' => 'Resistance',
                'ok' => Answers::close($resistance, $rx, 3),
                'detail' => Answers::close($resistance, $rx, 3)
                    ? sprintf('Correct: about %s Ω.', Answers::show($rx, 1))
                    : sprintf('Not quite. The resistor is %s Ω. Divide the voltmeter reading by the ammeter reading in amperes (mA ÷ 1000).', Answers::show($rx, 1)),
                'marks' => 50,
            ],
            [
                'label' => 'Predicted current',
                'ok' => Answers::close($current, $expectedCurrent, 3),
                'detail' => sprintf('I = V ÷ R = %d ÷ %s = %s mA.', (int) $p['vq'], Answers::show($rx, 1), Answers::show($expectedCurrent, 1)),
                'marks' => 30,
            ],
            [
                'label' => 'Readings',
                'ok' => $distinct >= 3,
                'detail' => $distinct >= 3
                    ? sprintf('You recorded %d different readings.', $distinct)
                    : 'Record at least three readings at different voltages: one reading can hide a mistake.',
                'marks' => 20,
            ],
        ], ['resistance' => $answers['resistance'] ?? '', 'current' => $answers['current'] ?? '', 'readings' => $readings]);
    }

    /** @return list<array{0: float, 1: float}> at most 20 [volts, milliamps] pairs */
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
