<?php

declare(strict_types=1);

namespace App\Modules\Joining;

use App\Modules\Courses\Course;

/**
 * "Suggested for you": published courses ordered by how well they fit what
 * the learner said they want (goals matched in the title, summary and
 * outcomes; the school-subject track) and their experience (course level).
 * Each suggestion says why, in the learner's own words.
 */
final class Recommendations
{
    /**
     * goal => words that show a course is about it. Each matches whole words
     * only; a trailing * also matches longer forms ("program*" finds
     * "programming" but "app" never matches "approach").
     */
    private const WORDS = [
        'code' => ['program*', 'code', 'coding', 'coder*', 'javascript', 'python', 'software', 'web', 'websites', 'html', 'css', 'developer*', 'app', 'apps', 'algorithm*'],
        'networks' => ['network*', 'subnet*', 'linux', 'server*', 'cloud', 'ip address*', 'router*', 'it support', 'data travels'],
        'security' => ['security', 'secure', 'phishing', 'cyber*', 'firewall*', 'password*', 'staying safe', 'safe online'],
        'data' => ['data', 'analytics', 'spreadsheet*', 'excel', 'statistic*', 'ai', 'artificial intelligence', 'machine learning'],
        'school' => [],
        'career' => ['job', 'jobs', 'job-ready', 'career*', 'employer*', 'professional*', 'workplace'],
    ];

    /** experience => [level => points] */
    private const LEVEL_FIT = [
        'new' => ['beginner' => 2, 'intermediate' => 0, 'advanced' => -2],
        'some' => ['beginner' => 1, 'intermediate' => 2, 'advanced' => 0],
        'pro' => ['beginner' => -1, 'intermediate' => 1, 'advanced' => 2],
    ];

    /**
     * @param list<Course> $courses courses the learner has not joined
     * @return list<array{course: Course, reasons: list<string>, score: int}> best first; only courses that fit something
     */
    public static function rank(LearnerProfile $profile, array $courses, int $limit = 6): array
    {
        $out = [];
        foreach ($courses as $course) {
            $text = ' ' . mb_strtolower($course->title . ' ' . $course->summary . ' ' . implode(' ', $course->outcomes)) . ' ';
            $score = 0;
            $reasons = [];
            foreach ($profile->goals as $goal) {
                $hit = match ($goal) {
                    'school' => $course->track === 'school',
                    'everyday' => $course->track === 'everyday',
                    default => self::mentions($text, self::WORDS[$goal] ?? []),
                };
                if ($hit) {
                    $score += 3;
                    $reasons[] = LearnerProfile::GOALS[$goal][0];
                }
            }
            if ($profile->experience !== null && $course->level !== null) {
                $fit = self::LEVEL_FIT[$profile->experience][$course->level] ?? 0;
                $score += $fit;
                if ($fit === 2 && $reasons !== []) {
                    $reasons[] = 'Right for ' . mb_strtolower(LearnerProfile::EXPERIENCE[$profile->experience][0]);
                }
            }
            if ($reasons !== []) {
                $out[] = ['course' => $course, 'reasons' => $reasons, 'score' => $score];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['course']->title, $b['course']->title));

        return array_slice($out, 0, $limit);
    }

    /** @param list<string> $words */
    private static function mentions(string $text, array $words): bool
    {
        foreach ($words as $word) {
            $prefix = str_ends_with($word, '*');
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(rtrim($word, '*'), '/') . ($prefix ? '' : '(?![\p{L}\p{N}])') . '/u';
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
