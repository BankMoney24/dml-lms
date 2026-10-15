<?php

declare(strict_types=1);

namespace App\Modules\Achievements;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Modules\Notifications\Notifier;
use App\Modules\Paths\PathRepository;
use App\Modules\Paths\PathService;

/**
 * Achievements (ADR 0023). Each one is earned by something that really
 * happened, read from the learning records, so nothing can be claimed by hand
 * and nothing is lost if a hook is missed. refresh() records new ones with the
 * time the thing happened, and tells the learner about recent ones only (no
 * flood of old news the first time it runs).
 */
final class Achievements
{
    /** code => [name, how to earn it, icon] — in the order they are shown. */
    public const BADGES = [
        'first-lesson' => ['First step', 'Finish your first lesson.', 'lesson'],
        'first-course' => ['Finisher', 'Complete a whole course.', 'certificate'],
        'three-courses' => ['Hat-trick', 'Complete three courses.', 'courses'],
        'full-marks' => ['Full marks', 'Score 100% on a quiz.', 'review'],
        'hands-on' => ['Hands on', 'Pass a virtual lab.', 'lab'],
        'week-streak' => ['Week streak', 'Learn on seven days in a row.', 'calendar'],
        'curious' => ['Curious', 'Ask a question under a lesson.', 'help'],
        'helper' => ['Helper', 'Have your answer marked as the answer to a classmate\'s question.', 'groups'],
        'coder' => ['Coder', 'Save five snippets in the code playground.', 'code'],
        'path' => ['Path finisher', 'Finish every course in a learning path you follow.', 'link'],
    ];

    /** Earned longer ago than this when first noticed: recorded quietly. */
    private const NEWS_WINDOW = '-2 days';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly ?Notifier $notifier = null,
        private readonly ?PathRepository $paths = null,
        private readonly ?PathService $pathService = null,
    ) {
    }

    /** @return array<string, string> code => earned_at (UTC), in badge order */
    public function earned(int $tenantId, int $userId): array
    {
        $rows = $this->db->fetchAll('SELECT code, earned_at FROM achievements WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['code']] = (string) $r['earned_at'];
        }
        $out = [];
        foreach (array_keys(self::BADGES) as $code) {
            if (isset($byCode[$code])) {
                $out[$code] = $byCode[$code];
            }
        }

        return $out;
    }

    /**
     * Checks every achievement not yet earned and records the new ones.
     *
     * @param ?int $bestStreak the learner's longest run of learning days, when the caller already knows it
     * @return list<string> codes earned just now
     */
    public function refresh(int $tenantId, int $userId, ?int $bestStreak = null): array
    {
        $have = $this->earned($tenantId, $userId);
        $now = $this->clock->sqlNow();
        $new = [];
        foreach (array_keys(self::BADGES) as $code) {
            if (isset($have[$code])) {
                continue;
            }
            $when = $this->check($code, $tenantId, $userId, $bestStreak, $now);
            if ($when === null) {
                continue;
            }
            $this->db->execute(
                'INSERT IGNORE INTO achievements (tenant_id, user_id, code, earned_at, recorded_at) VALUES (?, ?, ?, ?, ?)',
                [$tenantId, $userId, $code, $when, $now],
            );
            $new[] = $code;
            if ($when >= $this->clock->now()->modify(self::NEWS_WINDOW)->format('Y-m-d H:i:s')) {
                [$name, $how] = self::BADGES[$code];
                $this->notifier?->notify($tenantId, $userId, 'achievement', 'Achievement: ' . $name, $how, '/achievements');
            }
        }

        return $new;
    }

    /** @return ?string when it was earned (UTC), or null when not yet */
    private function check(string $code, int $tenantId, int $userId, ?int $bestStreak, string $now): ?string
    {
        $value = match ($code) {
            'first-lesson' => $this->db->fetchValue(
                "SELECT MIN(lp.completed_at) FROM lesson_progress lp JOIN enrolments e ON e.tenant_id = lp.tenant_id AND e.id = lp.enrolment_id
                 WHERE lp.tenant_id = ? AND e.user_id = ? AND lp.status = 'completed'",
                [$tenantId, $userId],
            ),
            'first-course' => $this->db->fetchValue('SELECT MIN(completed_at) FROM enrolments WHERE tenant_id = ? AND user_id = ? AND completed_at IS NOT NULL', [$tenantId, $userId]),
            'three-courses' => $this->db->fetchValue(
                'SELECT completed_at FROM enrolments WHERE tenant_id = ? AND user_id = ? AND completed_at IS NOT NULL ORDER BY completed_at LIMIT 1 OFFSET 2',
                [$tenantId, $userId],
            ),
            'full-marks' => $this->db->fetchValue('SELECT MIN(submitted_at) FROM quiz_attempts WHERE tenant_id = ? AND user_id = ? AND score_percent >= 100', [$tenantId, $userId]),
            'hands-on' => $this->db->fetchValue(
                'SELECT MIN(COALESCE(ended_at, started_at)) FROM lab_sessions WHERE tenant_id = ? AND user_id = ? AND passed = 1',
                [$tenantId, $userId],
            ),
            'week-streak' => $bestStreak !== null && $bestStreak >= 7 ? $now : null,
            'curious' => $this->db->fetchValue('SELECT MIN(created_at) FROM lesson_questions WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]),
            'helper' => $this->db->fetchValue(
                "SELECT MIN(a.created_at) FROM lesson_answers a
                 JOIN lesson_questions q ON q.tenant_id = a.tenant_id AND q.accepted_answer_id = a.id AND q.status = 'open'
                 WHERE a.tenant_id = ? AND a.user_id = ? AND q.user_id <> a.user_id AND a.status = 'visible'",
                [$tenantId, $userId],
            ),
            'coder' => $this->db->fetchValue(
                'SELECT created_at FROM code_snippets WHERE tenant_id = ? AND user_id = ? ORDER BY created_at LIMIT 1 OFFSET 4',
                [$tenantId, $userId],
            ),
            'path' => $this->finishedPath($tenantId, $userId) ? $now : null,
            default => null,
        };

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function finishedPath(int $tenantId, int $userId): bool
    {
        if ($this->paths === null || $this->pathService === null) {
            return false;
        }
        foreach ($this->paths->followedBy($tenantId, $userId) as $path) {
            if ($this->pathService->progress($path, $userId)['complete']) {
                return true;
            }
        }

        return false;
    }
}
