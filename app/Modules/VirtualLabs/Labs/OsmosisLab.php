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
 * Biology: osmosis in potato strips (ADR 0035). Strips are weighed, left in
 * sucrose solutions of different concentrations, and weighed again. The
 * percentage change in mass is plotted against concentration; where the line
 * crosses zero is the concentration of the cell sap (isotonic point), which is
 * different in every session.
 */
final class OsmosisLab implements Lab
{
    public const REASONS = [
        'out' => 'The solution was more concentrated than the cell sap, so water left the cells by osmosis',
        'in' => 'The solution was less concentrated than the cell sap, so water entered the cells by osmosis',
        'sugar_in' => 'Sugar moved into the cells, making them heavier',
        'sugar_out' => 'Sugar moved out of the cells, making them lighter',
    ];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'osmosis',
            version: 1,
            title: 'Osmosis in potato strips',
            subject: 'Biology',
            track: 'school',
            level: 'SS2',
            summary: 'Weigh potato strips before and after 30 minutes in sucrose solutions, plot the percentage change in mass, and find the concentration of the cell sap.',
            objective: 'Investigate osmosis and estimate the concentration of potato cell sap from where the percentage change in mass is zero.',
            tasks: [
                'Choose a sucrose solution, weigh a fresh strip, leave it for 30 minutes and weigh it again.',
                'Do this for at least five different concentrations from 0.0 M to 1.0 M.',
                'The table works out the percentage change in mass: (final − initial) ÷ initial × 100.',
                'Read from the graph the concentration where the change is zero: that solution is isotonic with the cell sap.',
            ],
            prerequisites: 'What osmosis and a partially permeable membrane are.',
            minutes: 30,
        );
    }

    public function params(Seed $seed): array
    {
        return [
            'public' => ['iso' => $seed->int(24, 46) / 100, 'strong' => $seed->pick([0.8, 0.9, 1.0])],
            'secret' => [],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('iso', 'Concentration of the cell sap (isotonic point)', 'number', 'M', 'To 2 decimal places, read from your graph.'),
            new LabField('strong', sprintf('In the %s M solution, the strip…', Answers::show((float) $public['strong'], 1)), 'select', '', '', [
                '' => 'Choose', 'lost' => 'lost mass', 'gained' => 'gained mass', 'same' => 'stayed the same',
            ]),
            new LabField('reason', 'Why?', 'select', '', '', ['' => 'Choose'] + self::REASONS),
            new LabField('readings', 'Your readings', 'hidden'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $iso = (float) $params['public']['iso'];
        $readings = self::readings((string) ($answers['readings'] ?? ''));
        $levels = count(array_unique(array_map(static fn (array $r): string => (string) round($r[0], 2), $readings)));
        $value = Answers::number($answers['iso'] ?? null);

        return LabResult::fromParts([
            ['label' => 'Cell sap concentration', 'ok' => $value !== null && abs($value - $iso) <= 0.04, 'marks' => 40,
                'detail' => sprintf('The change in mass is zero at about %s M, so the cell sap is about %s M.', Answers::show($iso, 2), Answers::show($iso, 2))],
            ['label' => 'Strongest solution', 'ok' => ($answers['strong'] ?? '') === 'lost', 'marks' => 20,
                'detail' => 'The strip lost mass: the solution was more concentrated than the cell sap.'],
            ['label' => 'Explanation', 'ok' => ($answers['reason'] ?? '') === 'out', 'marks' => 20,
                'detail' => 'Water moves by osmosis from the more dilute side (the cell sap) to the more concentrated side, through the partially permeable membranes. Sucrose does not cross them.'],
            ['label' => 'Readings', 'ok' => $levels >= 5, 'marks' => 20,
                'detail' => $levels >= 5 ? sprintf('You tested %d concentrations.', $levels) : 'Test at least five concentrations, on both sides of the isotonic point.'],
        ], ['iso' => $answers['iso'] ?? '', 'strong' => $answers['strong'] ?? '', 'reason' => $answers['reason'] ?? '', 'readings' => $readings]);
    }

    /** @return list<array{0: float, 1: float, 2: float}> [concentration M, initial g, final g] */
    private static function readings(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) ? array_slice($data, 0, 20) : [] as $row) {
            if (is_array($row) && is_numeric($row[0] ?? null) && is_numeric($row[1] ?? null) && is_numeric($row[2] ?? null)) {
                $out[] = [(float) $row[0], (float) $row[1], (float) $row[2]];
            }
        }

        return $out;
    }
}
