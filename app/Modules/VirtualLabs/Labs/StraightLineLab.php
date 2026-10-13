<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Answers;
use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/** Mathematics, SS1: find the equation y = mx + c of the line through plotted points. */
final class StraightLineLab implements Lab
{
    private const GRADIENTS = [-3.0, -2.5, -2.0, -1.5, -1.0, -0.5, 0.5, 1.0, 1.5, 2.0, 2.5, 3.0];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'straight-line',
            version: 1,
            title: 'Straight-line graphs: find the equation',
            subject: 'Mathematics',
            track: 'school',
            level: 'SS1',
            summary: 'Points lie on a straight line. Find its gradient and intercept, then use the equation.',
            objective: 'Find the gradient and y-intercept of a line from points on a graph and use y = mx + c.',
            tasks: [
                'Pick two of the plotted points and work out the gradient: change in y ÷ change in x.',
                'Read or calculate where the line crosses the y-axis (the intercept c).',
                'Use y = mx + c to find y for the x value in the question. Use the sliders to check your line passes through every point.',
            ],
            prerequisites: 'Plotting coordinates; substituting into a formula.',
            minutes: 25,
        );
    }

    public function params(Seed $seed): array
    {
        $m = $seed->pick(self::GRADIENTS);
        $c = $seed->int(-6, 6);
        $xs = [];
        for ($x = -8; $x <= 8; $x++) {
            $y = $m * $x + $c;
            // Whole-number points that stay on the grid.
            if (abs($y) <= 9 && floor($y) === $y) {
                $xs[] = $x;
            }
        }
        $chosen = array_slice($seed->shuffle($xs), 0, 5);
        sort($chosen);
        $question = $seed->pick(array_values(array_diff(range(-12, 12), $chosen)) ?: [10]);

        return [
            'public' => [
                'points' => array_map(static fn (int $x): array => [$x, $m * $x + $c], $chosen),
                'xq' => $question,
            ],
            'secret' => ['m' => $m, 'c' => $c],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('gradient', 'Gradient m'),
            new LabField('intercept', 'Intercept c'),
            new LabField('y', sprintf('Value of y when x = %s', str_replace('-', '−', (string) (int) $public['xq']))),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $m = (float) $params['secret']['m'];
        $c = (float) $params['secret']['c'];
        $xq = (int) $params['public']['xq'];
        $y = $m * $xq + $c;
        $gotM = Answers::number($answers['gradient'] ?? null);
        $gotC = Answers::number($answers['intercept'] ?? null);
        $gotY = Answers::number($answers['y'] ?? null);

        $sign = $c < 0 ? '−' : '+';

        return LabResult::fromParts([
            ['label' => 'Gradient', 'ok' => Answers::close($gotM, $m, 0, 0.01), 'detail' => sprintf('m = %s.', Answers::show($m)), 'marks' => 35],
            [
                'label' => 'Intercept',
                'ok' => Answers::close($gotC, $c, 0, 0.01),
                'detail' => sprintf('c = %s, so the line is y = %sx %s %s.', Answers::show($c), Answers::show($m), $sign, Answers::show(abs($c))),
                'marks' => 35,
            ],
            [
                'label' => 'Using the equation',
                'ok' => Answers::close($gotY, $y, 0, 0.01),
                'detail' => sprintf('y = %s × %d %s %s = %s.', Answers::show($m), $xq, $sign, Answers::show(abs($c)), Answers::show($y)),
                'marks' => 30,
            ],
        ], ['gradient' => $answers['gradient'] ?? '', 'intercept' => $answers['intercept'] ?? '', 'y' => $answers['y'] ?? '']);
    }
}
