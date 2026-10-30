<?php

declare(strict_types=1);

namespace App\Modules\Guardians;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Modules\Certificates\CertificateService;
use App\Modules\Groups\GroupRepository;
use App\Modules\LiveClasses\ClassSessionRepository;
use App\Modules\Tenancy\Tenant;

/**
 * A printable report for one learner over a period (ADR 0032): courses and how far
 * through they are, quiz averages and results, assignments, attendance at live
 * classes in the period, and certificates. Built only from what the platform
 * records; there are no made-up grades or comments. For a parent, each part
 * follows what their link allows (progress and attendance, results).
 */
final class ReportSheet
{
    public const MAX_DAYS = 400;

    public function __construct(
        private readonly Connection $db,
        private readonly ChildReport $report,
        private readonly GroupRepository $groups,
        private readonly CertificateService $certificates,
        private readonly Clock $clock,
        private readonly ?ClassSessionRepository $classes = null,
    ) {
    }

    /**
     * The period from ?from= and ?to= (dates in the school's time zone); the last 90 days by default.
     *
     * @return array{from: string, to: string, fromUtc: string, toUtc: string, error: ?string}
     */
    public function period(Tenant $tenant, ?string $from, ?string $to): array
    {
        $zone = new \DateTimeZone($tenant->timezone !== '' ? $tenant->timezone : 'UTC');
        $today = $this->clock->now()->setTimezone($zone);
        $parse = static function (?string $d) use ($zone): ?\DateTimeImmutable {
            $date = $d === null || $d === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $d, $zone);

            return $date === false || $date->format('Y-m-d') !== $d ? null : $date;
        };
        $error = null;
        $end = $parse($to) ?? $today;
        $start = $parse($from) ?? $end->modify('-90 days');
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diff($end)->days > self::MAX_DAYS) {
            $start = $end->modify('-' . self::MAX_DAYS . ' days');
            $error = 'A report covers at most ' . self::MAX_DAYS . ' days, so it starts on ' . $start->format('j M Y') . '.';
        }
        $utc = new \DateTimeZone('UTC');

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'fromUtc' => $start->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'toUtc' => $end->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'error' => $error,
        ];
    }

    /**
     * @param array{fromUtc: string, toUtc: string} $period
     * @return array{
     *   groups: list<string>,
     *   courses: ?list<array{title: string, status: string, done: int, total: int, last_at: ?string, average: ?int}>,
     *   lessonsInPeriod: ?int,
     *   attendance: ?array{held: int, present: int, late: int, absent: int, excused: int, unmarked: int, rate: ?int},
     *   results: ?array{
     *     quizzes: list<array{course: string, lesson: string, best: int, passed: bool, attempts: int}>,
     *     assignments: list<array{course: string, lesson: string, status: string, at: string}>,
     *     exams: list<array{title: string, at: string, total: int, max: int, percent: int, sections: list<array{label: string, scaled: int}>}>
     *   },
     *   certificates: ?list<\App\Modules\Certificates\Certificate>
     * }
     */
    public function build(int $tenantId, int $learnerId, array $period, bool $progress, bool $results): array
    {
        $courses = null;
        if ($progress) {
            $averages = $this->averages($tenantId, $learnerId);
            $courses = array_map(static fn (array $c): array => [
                'title' => $c['title'], 'status' => $c['status'], 'done' => $c['done'], 'total' => $c['total'], 'last_at' => $c['last_at'],
                'average' => $results ? ($averages[$c['slug']] ?? null) : null,
            ], $this->report->courses($tenantId, $learnerId));
        }
        $attendance = null;
        if ($progress && $this->classes !== null) {
            $summary = $this->classes->learnerSummary($tenantId, $learnerId, min($period['toUtc'], $this->clock->now()->format('Y-m-d H:i:s')), 0, $period['fromUtc']);
            unset($summary['recent']);
            $attendance = $summary;
        }

        return [
            'groups' => array_map(static fn (array $g): string => $g['group']->name, $this->groups->forLearner($tenantId, $learnerId)),
            'courses' => $courses,
            'lessonsInPeriod' => $progress ? (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM lesson_progress p JOIN enrolments e ON e.tenant_id = p.tenant_id AND e.id = p.enrolment_id
                 WHERE p.tenant_id = ? AND e.user_id = ? AND p.status = 'completed' AND p.completed_at BETWEEN ? AND ?",
                [$tenantId, $learnerId, $period['fromUtc'], $period['toUtc']],
            ) : null,
            'attendance' => $attendance,
            'results' => $results ? $this->report->results($tenantId, $learnerId) : null,
            'certificates' => $results || $progress ? $this->certificates->forUser($tenantId, $learnerId) : null,
        ];
    }

    /**
     * Average of the best score on each required quiz, per course (a quiz not tried counts 0).
     *
     * @return array<string, int> course slug => average
     */
    private function averages(int $tenantId, int $learnerId): array
    {
        $out = [];
        foreach (
            $this->db->fetchAll(
                "SELECT slug, FLOOR(AVG(best)) AS average FROM (
                    SELECT c.slug, a.id, COALESCE(MAX(q.score_percent), 0) AS best
                    FROM enrolments e
                    JOIN courses c ON c.tenant_id = e.tenant_id AND c.id = e.course_id
                    JOIN lessons l ON l.tenant_id = c.tenant_id AND l.course_id = c.id AND l.is_mandatory = 1
                    JOIN assessments a ON a.tenant_id = l.tenant_id AND a.lesson_id = l.id
                    LEFT JOIN quiz_attempts q ON q.tenant_id = a.tenant_id AND q.assessment_id = a.id AND q.enrolment_id = e.id
                    WHERE e.tenant_id = ? AND e.user_id = ? AND e.status <> 'withdrawn'
                    GROUP BY c.slug, a.id
                 ) t GROUP BY slug",
                [$tenantId, $learnerId],
            ) as $r
        ) {
            $out[(string) $r['slug']] = (int) $r['average'];
        }

        return $out;
    }
}
