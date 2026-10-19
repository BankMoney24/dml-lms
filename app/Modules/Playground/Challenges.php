<?php

declare(strict_types=1);

namespace App\Modules\Playground;

/**
 * Practice challenges for the Code studio (ADR 0027): write one function in
 * JavaScript or Python and run it against example cases in the browser's
 * sealed runner. Code never runs on the server, so a pass is the learner's
 * own practice record, not a marked result.
 */
final class Challenges
{
    public const LANGUAGES = ['javascript' => 'JavaScript', 'python' => 'Python'];

    /**
     * @return array<string, array{title: string, topic: string, level: string, prompt: string, arg: string, js: string, py: string, cases: list<array{0: list<mixed>, 1: mixed}>}>
     */
    public static function all(): array
    {
        // phpcs:disable Generic.Files.LineLength
        return [
            'greet' => [
                'title' => 'Say hello',
                'topic' => 'Strings',
                'level' => 'First steps',
                'prompt' => "Write a function that takes a **name** and returns a greeting.\n\n`greet(\"Ada\")` returns `\"Hello, Ada!\"`.",
                'arg' => 'name',
                'js' => 'greet',
                'py' => 'greet',
                'cases' => [[['Ada'], 'Hello, Ada!'], [['Tobi'], 'Hello, Tobi!'], [['Chiamaka'], 'Hello, Chiamaka!']],
            ],
            'even' => [
                'title' => 'Odd or even',
                'topic' => 'Numbers',
                'level' => 'First steps',
                'prompt' => "Return **true** if the number is even and **false** if it is odd. Zero is even; negative numbers can be either.\n\nHint: the remainder operator `%`.",
                'arg' => 'n',
                'js' => 'isEven',
                'py' => 'is_even',
                'cases' => [[[4], true], [[7], false], [[0], true], [[-3], false], [[-10], true]],
            ],
            'grade' => [
                'title' => 'Scores into grades',
                'topic' => 'Decisions',
                'level' => 'First steps',
                'prompt' => "Turn a score from 0 to 100 into a grade:\n\n| Score | Grade |\n|---|---|\n| 70 and above | A |\n| 60 to 69 | B |\n| 50 to 59 | C |\n| 45 to 49 | D |\n| 40 to 44 | E |\n| below 40 | F |\n\nTest the boundaries: 70, 69, 45, 44, 40, 39.",
                'arg' => 'score',
                'js' => 'grade',
                'py' => 'grade',
                'cases' => [[[85], 'A'], [[70], 'A'], [[69], 'B'], [[60], 'B'], [[55], 'C'], [[50], 'C'], [[47], 'D'], [[45], 'D'], [[44], 'E'], [[40], 'E'], [[39], 'F'], [[0], 'F']],
            ],
            'largest' => [
                'title' => 'The largest number',
                'topic' => 'Lists',
                'level' => 'First steps',
                'prompt' => "Given a list of numbers (at least one), return the **largest**. Try it without the built-in max, using a loop.",
                'arg' => 'numbers',
                'js' => 'largest',
                'py' => 'largest',
                'cases' => [[[[3, 9, 2]], 9], [[[-5, -2, -9]], -2], [[[7]], 7], [[[1, 1, 1]], 1]],
            ],
            'vowels' => [
                'title' => 'Count the vowels',
                'topic' => 'Strings',
                'level' => 'Getting going',
                'prompt' => "Count the vowels (a, e, i, o, u) in a piece of text. Capital letters count too.",
                'arg' => 'text',
                'js' => 'countVowels',
                'py' => 'count_vowels',
                'cases' => [[['education'], 5], [['Rhythm'], 0], [['AEIOU aeiou'], 10], [[''], 0]],
            ],
            'reverse-words' => [
                'title' => 'Words backwards',
                'topic' => 'Strings',
                'level' => 'Getting going',
                'prompt' => "Return the sentence with its **words** in reverse order (the letters in each word stay as they are). Words are separated by single spaces.",
                'arg' => 'sentence',
                'js' => 'reverseWords',
                'py' => 'reverse_words',
                'cases' => [[['I love Lagos'], 'Lagos love I'], [['one'], 'one'], [['code every day'], 'day every code']],
            ],
            'fizzbuzz' => [
                'title' => 'FizzBuzz',
                'topic' => 'Decisions',
                'level' => 'Getting going',
                'prompt' => "Return `\"Fizz\"` if the number divides by 3, `\"Buzz\"` if it divides by 5, `\"FizzBuzz\"` if it divides by both, and otherwise the number itself **as text**.",
                'arg' => 'n',
                'js' => 'fizzBuzz',
                'py' => 'fizz_buzz',
                'cases' => [[[3], 'Fizz'], [[5], 'Buzz'], [[15], 'FizzBuzz'], [[7], '7'], [[30], 'FizzBuzz'], [[1], '1']],
            ],
            'digits' => [
                'title' => 'Add up the digits',
                'topic' => 'Loops',
                'level' => 'Getting going',
                'prompt' => "Add up the digits of a whole number that is 0 or more. `1234` gives `10`.\n\nOne way: take the last digit with `% 10`, then drop it with whole-number division by 10.",
                'arg' => 'n',
                'js' => 'sumDigits',
                'py' => 'sum_digits',
                'cases' => [[[1234], 10], [[9], 9], [[1000], 1], [[0], 0], [[98765], 35]],
            ],
            'palindrome' => [
                'title' => 'Palindromes',
                'topic' => 'Strings',
                'level' => 'Stretch',
                'prompt' => "A palindrome reads the same forwards and backwards. Ignore capital letters and spaces: \"Never odd or even\" is a palindrome.",
                'arg' => 'text',
                'js' => 'isPalindrome',
                'py' => 'is_palindrome',
                'cases' => [[['Racecar'], true], [['Never odd or even'], true], [['Lagos'], false], [['a'], true]],
            ],
            'fahrenheit' => [
                'title' => 'Celsius to Fahrenheit',
                'topic' => 'Numbers',
                'level' => 'First steps',
                'prompt' => "Convert a temperature from Celsius to Fahrenheit: multiply by 9, divide by 5, then add 32.",
                'arg' => 'celsius',
                'js' => 'toFahrenheit',
                'py' => 'to_fahrenheit',
                'cases' => [[[0], 32], [[100], 212], [[-40], -40], [[25], 77], [[37], 98.6]],
            ],
            'words' => [
                'title' => 'Count the words',
                'topic' => 'Strings',
                'level' => 'Stretch',
                'prompt' => "Count the words in a piece of text. There may be extra spaces at the start, at the end or between words, and the text may be empty.",
                'arg' => 'text',
                'js' => 'countWords',
                'py' => 'count_words',
                'cases' => [[['the cat sat'], 3], [['  spaced   out  '], 2], [[''], 0], [['one'], 1]],
            ],
            'hosts' => [
                'title' => 'Hosts in a subnet',
                'topic' => 'Networking',
                'level' => 'Stretch',
                'prompt' => "Given an IPv4 prefix length from 1 to 30, return how many **usable** host addresses the subnet has: 2 to the power of (32 − prefix), minus 2.",
                'arg' => 'prefix',
                'js' => 'usableHosts',
                'py' => 'usable_hosts',
                'cases' => [[[24], 254], [[26], 62], [[30], 2], [[20], 4094], [[16], 65534]],
            ],
        ];
        // phpcs:enable Generic.Files.LineLength
    }

    /** @return ?array{title: string, topic: string, level: string, prompt: string, arg: string, js: string, py: string, cases: list<array{0: list<mixed>, 1: mixed}>} */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Starting code for a challenge in a language. */
    public static function starter(string $key, string $language): string
    {
        $c = self::find($key);
        if ($c === null) {
            return '';
        }
        $arg = $c['arg'];
        if ($language === 'python') {
            return "def {$c['py']}({$arg}):\n    # Write your code here\n    return None\n\n\n"
                . "# Try it out: this line runs when you press Run.\nprint({$c['py']}(" . self::pyLiteral($c['cases'][0][0][0] ?? null) . "))\n";
        }

        return "function {$c['js']}({$arg}) {\n  // Write your code here\n}\n\n"
            . "// Try it out: this line runs when you press Run.\nconsole.log({$c['js']}(" . json_encode($c['cases'][0][0][0] ?? null, JSON_UNESCAPED_UNICODE) . "));\n";
    }

    /**
     * The cases in the form the browser needs: arguments as JSON (JavaScript)
     * and as Python source, the expected value as JSON and as Python's repr().
     *
     * @return list<array{args: list<mixed>, py_args: string, expected: mixed, py_expected: string}>
     */
    public static function cases(string $key): array
    {
        $c = self::find($key);
        if ($c === null) {
            return [];
        }

        return array_map(static fn (array $case): array => [
            'args' => $case[0],
            'py_args' => implode(', ', array_map(self::pyLiteral(...), $case[0])),
            'expected' => $case[1],
            'py_expected' => self::pyLiteral($case[1]),
        ], $c['cases']);
    }

    /** A value written as Python source (also what repr() prints for these simple values). */
    public static function pyLiteral(mixed $v): string
    {
        return match (true) {
            $v === null => 'None',
            $v === true => 'True',
            $v === false => 'False',
            is_int($v) => (string) $v,
            is_float($v) => floor($v) === $v ? number_format($v, 1, '.', '') : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'),
            is_string($v) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'",
            is_array($v) => '[' . implode(', ', array_map(self::pyLiteral(...), $v)) . ']',
            default => 'None',
        };
    }
}
