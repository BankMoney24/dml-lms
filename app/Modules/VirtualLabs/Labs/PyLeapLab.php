<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Programming: write a Python function (ADR 0027). Like the JavaScript lab,
 * the code runs only in the learner's browser: Python (Skulpt) inside the
 * sealed runner, with no network and a time limit. The server sends fresh
 * years for each session and marks the outputs against its own answers.
 */
final class PyLeapLab implements Lab
{
    public const MAX_CODE = 8000;
    private const EXAMPLES = [[2024, 'True'], [2023, 'False'], [1900, 'False'], [2000, 'True']];

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'py-leap',
            version: 1,
            title: 'Python: leap years',
            subject: 'Programming',
            track: 'skills',
            level: 'Beginner',
            summary: 'Write a Python function that decides whether a year is a leap year, then test it on fresh years, including the tricky century years.',
            objective: 'Combine conditions with and / or / not in a Python function, and test the edge cases.',
            tasks: [
                'Complete is_leap(year) so it returns True for leap years and False otherwise.',
                'A year is a leap year if it divides by 4, except years that divide by 100, which are leap years only if they also divide by 400.',
                'Run the examples until they pass, then check your answers: your function is run on new years chosen for you.',
            ],
            prerequisites: 'Python variables, the remainder operator % and if / elif / else.',
            minutes: 30,
            runsLearnerCode: true,
        );
    }

    public static function reference(int $year): string
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 'True' : 'False';
    }

    public function params(Seed $seed): array
    {
        $centuriesNot = [1700, 1800, 1900, 2100, 2200, 2300, 2500];
        $centuriesLeap = [1600, 2000, 2400, 2800];
        $tests = [
            $seed->pick($centuriesNot), $seed->pick($centuriesNot), $seed->pick($centuriesLeap),
            $seed->int(475, 524) * 4, $seed->int(450, 499) * 4 + 2, $seed->int(440, 510) * 4 + 1, $seed->int(440, 510) * 4 + 3,
        ];
        while (count($tests) < 12) {
            $tests[] = $seed->int(1582, 2999);
        }
        $tests = $seed->shuffle(array_map('intval', $tests));

        return [
            'public' => [
                'tests' => $tests,
                'examples' => self::EXAMPLES,
                'starter' => "def is_leap(year):\n    # Return True for a leap year, False otherwise.\n    if year % 4 == 0:\n        return True\n    return False\n",
                'function' => 'is_leap',
                'skulpt' => ['/assets/vendor/skulpt/skulpt.min.js', '/assets/vendor/skulpt/skulpt-stdlib.js'],
            ],
            'secret' => ['expected' => array_map([self::class, 'reference'], $tests)],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('code', 'Your function', 'code', '', 'Python 3. It runs only in your browser, cut off from the internet and the rest of this site.', [], 14),
            new LabField('outputs', 'Results of the check', 'hidden'),
        ];
    }

    public function check(array $params, array $answers): LabResult
    {
        $tests = array_map('intval', (array) $params['public']['tests']);
        $expected = (array) $params['secret']['expected'];
        $decoded = json_decode($answers['outputs'] ?? '', true);
        $outputs = is_array($decoded) ? array_values($decoded) : [];
        $code = mb_substr($answers['code'] ?? '', 0, self::MAX_CODE);
        $evidence = ['code' => $code, 'outputs' => array_map(static fn (mixed $o): string => mb_substr(is_scalar($o) ? (string) $o : '', 0, 40), array_slice($outputs, 0, 12))];
        if (count($outputs) !== count($tests)) {
            return new LabResult(0, [[
                'label' => 'Running your function',
                'ok' => false,
                'detail' => 'Your function could not be run on the test years. Use “Run the examples” to check it works, then try again.',
            ]], $evidence);
        }
        $correct = 0;
        $wrong = [];
        foreach ($tests as $i => $year) {
            $got = is_scalar($outputs[$i]) ? trim((string) $outputs[$i]) : '';
            if ($got === $expected[$i]) {
                $correct++;
            } else {
                $wrong[] = sprintf('is_leap(%d) should be %s but returned %s.', $year, $expected[$i], $got === '' ? 'nothing' : mb_substr($got, 0, 30));
            }
        }
        $feedback = [['label' => 'Test years', 'ok' => $wrong === [], 'detail' => sprintf('%d of %d correct.', $correct, count($tests))]];
        foreach (array_slice($wrong, 0, 6) as $line) {
            $feedback[] = ['label' => 'Check', 'ok' => false, 'detail' => $line];
        }

        return new LabResult((int) floor($correct * 100 / max(1, count($tests))), $feedback, $evidence);
    }
}
