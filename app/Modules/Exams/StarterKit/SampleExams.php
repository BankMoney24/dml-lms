<?php

declare(strict_types=1);

namespace App\Modules\Exams\StarterKit;

/**
 * Draft CBT exams built on the sample banks (ADR 0043). They are added as drafts
 * with no dates: a teacher checks them, sets the times and classes, and publishes.
 * Section counts are capped at what each bank holds when the kit is installed.
 */
final class SampleExams
{
    private const ROOM = "Each subject is scored out of 100.\n"
        . 'Use the keys A to E to choose an answer, N for the next question, P for the previous one, F to flag a question and S to submit. '
        . 'The exam submits itself when the time is up.';

    /**
     * @return list<array{title: string, instructions: string, sections: list<array{0: string, 1: int, 2: string, 3: bool}>,
     *     choose: int, minutes: int, results: string, attempts: int}>
     *     sections are [bank name, questions, label, compulsory]
     */
    public static function all(): array
    {
        return [
            [
                'title' => 'Mock UTME: sciences (sample)',
                'instructions' => "Practice in the style of the UTME: Use of English plus three subjects you choose.\n" . self::ROOM,
                'sections' => [
                    ['Use of English (sample)', 20, 'Use of English', true],
                    ['Mathematics (sample)', 15, 'Mathematics', false],
                    ['Physics (sample)', 15, 'Physics', false],
                    ['Chemistry (sample)', 15, 'Chemistry', false],
                    ['Biology (sample)', 15, 'Biology', false],
                ],
                'choose' => 3, 'minutes' => 50, 'results' => 'immediately', 'attempts' => 2,
            ],
            [
                'title' => 'Mock UTME: arts and social sciences (sample)',
                'instructions' => "Practice in the style of the UTME: Use of English plus three subjects you choose.\n" . self::ROOM,
                'sections' => [
                    ['Use of English (sample)', 20, 'Use of English', true],
                    ['Government (sample)', 10, 'Government', false],
                    ['Economics (sample)', 10, 'Economics', false],
                    ['Geography (sample)', 10, 'Geography', false],
                    ['Commerce (sample)', 10, 'Commerce', false],
                    ['Civic Education (sample)', 10, 'Civic Education', false],
                ],
                'choose' => 3, 'minutes' => 40, 'results' => 'immediately', 'attempts' => 2,
            ],
            [
                'title' => 'Mathematics class test (sample)',
                'instructions' => "Twenty questions on number, algebra, geometry and statistics. You may use the calculator.\n" . self::ROOM,
                'sections' => [['Mathematics (sample)', 20, 'Mathematics', true]],
                'choose' => 0, 'minutes' => 30, 'results' => 'immediately', 'attempts' => 1,
            ],
            [
                'title' => 'Networking Fundamentals test (sample)',
                'instructions' => "For learners on the Networking Foundations course: addressing, devices, services, switching, routing and basic security.\n" . self::ROOM,
                'sections' => [
                    ['Networking Fundamentals (sample)', 11, 'Networking', true],
                    ['Cybersecurity Basics (sample)', 5, 'Security', true],
                ],
                'choose' => 0, 'minutes' => 20, 'results' => 'immediately', 'attempts' => 1,
            ],
            [
                'title' => 'Digital skills check (sample)',
                'instructions' => "Computer Studies for everyone, then two of Python, Linux and Cybersecurity.\n" . self::ROOM,
                'sections' => [
                    ['Computer Studies (sample)', 10, 'Computer Studies', true],
                    ['Python Programming (sample)', 10, 'Python', false],
                    ['Linux Basics (sample)', 10, 'Linux', false],
                    ['Cybersecurity Basics (sample)', 10, 'Cybersecurity', false],
                ],
                'choose' => 2, 'minutes' => 30, 'results' => 'immediately', 'attempts' => 2,
            ],
        ];
    }
}
