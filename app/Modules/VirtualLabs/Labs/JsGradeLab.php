<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Labs;

use App\Modules\VirtualLabs\Lab;
use App\Modules\VirtualLabs\LabDefinition;
use App\Modules\VirtualLabs\LabField;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\Seed;

/**
 * Programming: write a JavaScript function. The learner's code runs only in
 * their own browser, inside a sandboxed frame with no network access and a
 * time limit (see public/assets/labs/runner.js). The server sends a fresh
 * set of scores for each session and marks the outputs against its own
 * reference answers, so outputs cannot be copied from someone else.
 */
final class JsGradeLab implements Lab
{
    /** Scores on the grade boundaries are always included: that is where mistakes hide. */
    private const BOUNDARIES = [0, 39, 40, 44, 45, 49, 50, 59, 60, 69, 70, 100];
    private const EXAMPLES = [[85, 'A'], [62, 'B'], [47, 'D'], [12, 'F']];
    public const MAX_CODE = 8000;

    public function definition(): LabDefinition
    {
        return new LabDefinition(
            key: 'js-grades',
            version: 1,
            title: 'JavaScript: turn scores into grades',
            subject: 'Programming',
            track: 'skills',
            level: 'Beginner',
            summary: 'Write a function that converts an exam score into a letter grade, and test it on fresh scores.',
            objective: 'Write a function with if / else if conditions and test it, including the boundary values.',
            tasks: [
                'Complete the function grade(score) so it returns "A" for 70–100, "B" for 60–69, "C" for 50–59, "D" for 45–49, "E" for 40–44 and "F" below 40.',
                'Run the examples until they all pass. Think about the boundaries: 69 and 70, 44 and 45, 39 and 40.',
                'Check your answers: your function is run on new scores chosen for you.',
            ],
            prerequisites: 'JavaScript variables, comparison operators and if / else.',
            minutes: 30,
            runsLearnerCode: true,
        );
    }

    public static function reference(int $score): string
    {
        return match (true) {
            $score >= 70 => 'A',
            $score >= 60 => 'B',
            $score >= 50 => 'C',
            $score >= 45 => 'D',
            $score >= 40 => 'E',
            default => 'F',
        };
    }

    public function params(Seed $seed): array
    {
        $tests = array_slice($seed->shuffle(self::BOUNDARIES), 0, 8);
        while (count($tests) < 12) {
            $tests[] = $seed->int(0, 100);
        }
        $tests = $seed->shuffle($tests);

        return [
            'public' => [
                'tests' => $tests,
                'examples' => self::EXAMPLES,
                'starter' => "function grade(score) {\n  // Return \"A\", \"B\", \"C\", \"D\", \"E\" or \"F\".\n  if (score >= 70) {\n    return \"A\";\n  }\n  return \"F\";\n}\n",
                'function' => 'grade',
                'time_limit_ms' => 2000,
            ],
            'secret' => ['expected' => array_map([self::class, 'reference'], $tests)],
        ];
    }

    public function fields(array $public): array
    {
        return [
            new LabField('code', 'Your function', 'code', '', 'Plain JavaScript. It runs only in your browser, cut off from the internet and the rest of this site.', [], 14),
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
                'detail' => 'Your function could not be run on the test scores. Use “Run the examples” to check it works, then try again. Very old browsers cannot run this lab.',
            ]], $evidence);
        }

        $correct = 0;
        $wrong = [];
        foreach ($tests as $i => $score) {
            $got = is_scalar($outputs[$i]) ? trim((string) $outputs[$i], " \t\n\r\"'") : '';
            if ($got === $expected[$i]) {
                $correct++;
            } else {
                $wrong[] = sprintf('grade(%d) should be "%s" but returned %s.', $score, $expected[$i], $got === '' ? 'nothing' : '"' . mb_substr($got, 0, 30) . '"');
            }
        }

        $feedback = [[
            'label' => 'Test scores',
            'ok' => $wrong === [],
            'detail' => sprintf('%d of %d correct.', $correct, count($tests)),
        ]];
        foreach (array_slice($wrong, 0, 6) as $line) {
            $feedback[] = ['label' => 'Check', 'ok' => false, 'detail' => $line];
        }

        return new LabResult((int) floor($correct * 100 / max(1, count($tests))), $feedback, $evidence);
    }
}
