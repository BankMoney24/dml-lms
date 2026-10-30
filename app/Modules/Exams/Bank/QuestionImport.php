<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

/**
 * Reads many questions at once (ADR 0042), from either:
 *
 * Aiken text (as Moodle and many question tools export it), one question per block:
 *
 *     Which gas do plants take in for photosynthesis?
 *     A. Oxygen
 *     B. Carbon dioxide
 *     C. Nitrogen
 *     D. Hydrogen
 *     ANSWER: B
 *     EXPLANATION: Plants take in carbon dioxide and give out oxygen.   (optional)
 *     TOPIC: Plant nutrition                                          (optional)
 *     DIFFICULTY: easy                                                (optional: easy, medium, hard)
 *
 * or CSV with a header row: question, a, b, c, d, e, answer, explanation, topic, difficulty
 * ("option_a" and "prompt" also work). Options may be two to five; the answer is a letter.
 *
 * Nothing is saved unless every question reads cleanly, so a file is never half imported.
 */
final class QuestionImport
{
    public const MAX_QUESTIONS = 500;

    /**
     * @return array{0: list<array{prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int}>, 1: list<string>}
     *         the questions read, and problems ("Line 7: …")
     */
    public static function aiken(string $text): array
    {
        $lines = preg_split('/\R/u', str_replace("\u{FEFF}", '', $text)) ?: [];
        $questions = [];
        $errors = [];
        /** @var ?array{prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int, line: int} $q */
        $q = null;
        $state = 'idle'; // idle | prompt | options | after
        foreach ($lines as $i => $raw) {
            $n = $i + 1;
            $line = trim($raw);
            if ($line === '') {
                if ($state === 'after') {
                    self::finish($q, $questions, $errors, true);
                    $state = 'idle';
                } elseif ($state === 'prompt' && $q !== null) {
                    $q['prompt'] .= "\n\n";
                }
                continue;
            }
            if ($state === 'after' && $q !== null) {
                if (preg_match('/^(EXPLANATION|TOPIC|DIFFICULTY)\s*:\s*(.*)$/i', $line, $m) === 1) {
                    $value = trim($m[2]);
                    $key = strtolower($m[1]);
                    if ($key === 'difficulty') {
                        $level = self::difficulty($value);
                        if ($level === false) {
                            $errors[] = 'Line ' . $n . ': difficulty is easy, medium or hard.';
                        } else {
                            $q['difficulty'] = $level;
                        }
                    } elseif ($key === 'topic') {
                        $q['topic'] = $value;
                    } else {
                        $q['explanation'] = $value;
                    }
                    continue;
                }
                self::finish($q, $questions, $errors, true);
                $state = 'idle';
            }
            if ($state === 'idle' || $q === null) {
                $q = ['prompt' => '', 'options' => [], 'correct' => -1, 'explanation' => '', 'topic' => '', 'difficulty' => null, 'line' => $n];
                $state = 'prompt';
            }
            if (preg_match('/^ANSWER\s*:\s*([A-Ea-e])\s*$/', $line, $m) === 1) {
                $index = ord(strtoupper($m[1])) - 65;
                if (count($q['options']) < 2) {
                    $errors[] = 'Line ' . $n . ': a question needs at least two options before its answer.';
                } elseif ($index >= count($q['options'])) {
                    $errors[] = 'Line ' . $n . ': the answer ' . strtoupper($m[1]) . ' is not one of the options.';
                }
                $q['correct'] = $index;
                $state = 'after';
                continue;
            }
            if (preg_match('/^ANSWER\b/i', $line) === 1) {
                $errors[] = 'Line ' . $n . ': write the answer as one letter, for example ANSWER: B.';
                $state = 'after';
                continue;
            }
            if (preg_match('/^([A-Ea-e])[.)]\s+(.+)$/u', $line, $m) === 1 && trim($q['prompt']) !== '') {
                $expected = BankQuestion::LETTERS[count($q['options'])] ?? null;
                if ($expected === null) {
                    $errors[] = 'Line ' . $n . ': a question can have at most five options (A to E).';
                } elseif (strtoupper($m[1]) !== $expected) {
                    $errors[] = 'Line ' . $n . ': expected option ' . $expected . ' here.';
                }
                $q['options'][] = trim($m[2]);
                $state = 'options';
                continue;
            }
            if ($state === 'options') {
                // A new prompt after options with no ANSWER: report the old question
                // and read this line as the start of the next one, so later lines keep their meaning.
                self::finish($q, $questions, $errors, false);
                $q = ['prompt' => '', 'options' => [], 'correct' => -1, 'explanation' => '', 'topic' => '', 'difficulty' => null, 'line' => $n];
                $state = 'prompt';
            }
            $q['prompt'] .= ($q['prompt'] === '' || str_ends_with($q['prompt'], "\n\n") ? '' : "\n") . $line;
        }
        self::finish($q, $questions, $errors, $state === 'after');

        return self::checked($questions, $errors);
    }

    /**
     * @param ?array{prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int, line: int} $q
     * @param-out null $q
     * @param list<array<string, mixed>> $questions
     * @param list<string> $errors
     */
    private static function finish(?array &$q, array &$questions, array &$errors, bool $answered): void
    {
        if ($q !== null && !$answered) {
            $errors[] = 'Line ' . $q['line'] . ': this question has no ANSWER line (for example ANSWER: B).';
        } elseif ($q !== null) {
            $questions[] = $q;
        }
        $q = null;
    }

    /**
     * @return array{0: list<array{prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int}>, 1: list<string>}
     */
    public static function csv(string $bytes): array
    {
        $bytes = str_replace("\u{FEFF}", '', $bytes);
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return [[], ['The file could not be read.']];
        }
        fwrite($handle, $bytes);
        rewind($handle);
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($header)) {
            return [[], ['The file is empty.']];
        }
        $map = [];
        foreach ($header as $i => $name) {
            $name = strtolower(trim((string) $name));
            $name = (string) preg_replace('/^option[ _]?/', '', $name);
            $name = $name === 'prompt' ? 'question' : $name;
            $map[$name] = $i;
        }
        if (!isset($map['question'], $map['a'], $map['b'], $map['answer'])) {
            fclose($handle);

            return [[], ['The first row must name the columns: question, a, b, c, d, e, answer, explanation, topic, difficulty.']];
        }
        $questions = [];
        $errors = [];
        $row = 1;
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $row++;
            $get = static fn (string $k): string => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            if (implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $options = [];
            foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
                if ($get($letter) !== '') {
                    $options[] = $get($letter);
                }
            }
            $answer = strtoupper($get('answer'));
            $index = preg_match('/^[A-E]$/', $answer) === 1 ? ord($answer) - 65 : -1;
            if ($index < 0) {
                $errors[] = 'Row ' . $row . ': the answer must be a letter from A to E.';
            }
            $level = self::difficulty($get('difficulty'));
            if ($level === false) {
                $errors[] = 'Row ' . $row . ': difficulty is easy, medium or hard.';
                $level = null;
            }
            $questions[] = ['prompt' => $get('question'), 'options' => $options, 'correct' => $index, 'explanation' => $get('explanation'), 'topic' => $get('topic'),
                'difficulty' => $level, 'row' => $row];
        }
        fclose($handle);

        return self::checked($questions, $errors, 'Row');
    }

    /**
     * Checks each question's shape the same way the form does.
     *
     * @param list<array<string, mixed>> $questions
     * @param list<string> $errors
     * @return array{0: list<array{prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int}>, 1: list<string>}
     */
    private static function checked(array $questions, array $errors, string $unit = 'Question'): array
    {
        $out = [];
        foreach ($questions as $i => $q) {
            $where = $unit === 'Row' ? 'Row ' . (int) ($q['row'] ?? 0) : 'Question ' . ($i + 1);
            $prompt = trim((string) $q['prompt']);
            /** @var list<string> $options */
            $options = array_values(array_map('strval', (array) $q['options']));
            $problem = match (true) {
                $prompt === '' => 'the question text is missing',
                mb_strlen($prompt) > 3000 => 'the question is longer than 3,000 characters',
                count($options) < 2 => 'it needs at least two options',
                count($options) > 5 => 'it has more than five options',
                max(array_map('mb_strlen', $options)) > 500 => 'an option is longer than 500 characters',
                (int) $q['correct'] < 0 || (int) $q['correct'] >= count($options) => 'the answer is not one of its options',
                mb_strlen((string) $q['explanation']) > 2000 => 'the explanation is longer than 2,000 characters',
                mb_strlen((string) $q['topic']) > 80 => 'the topic is longer than 80 characters',
                default => null,
            };
            if ($problem !== null) {
                $errors[] = $where . ': ' . $problem . '.';
                continue;
            }
            $out[] = [
                'prompt' => $prompt, 'options' => $options, 'correct' => (int) $q['correct'],
                'explanation' => trim((string) $q['explanation']), 'topic' => trim((string) $q['topic']),
                'difficulty' => is_int($q['difficulty'] ?? null) ? $q['difficulty'] : null,
            ];
        }
        if ($out === [] && $errors === []) {
            $errors[] = 'No questions were found. Check the format against the example.';
        }
        if (count($out) > self::MAX_QUESTIONS) {
            $errors[] = 'Import up to ' . self::MAX_QUESTIONS . ' questions at a time.';
        }

        return [$out, array_values(array_unique($errors))];
    }

    private static function difficulty(string $value): int|false|null
    {
        return match (strtolower(trim($value))) {
            '' => null,
            'easy', '1' => 1,
            'medium', '2' => 2,
            'hard', '3' => 3,
            default => false,
        };
    }
}
