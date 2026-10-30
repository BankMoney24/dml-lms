<?php

declare(strict_types=1);

namespace App\Modules\Exams\Cbt;

use App\Core\Clock;
use App\Core\Database\Connection;

/** CBT exams and attempts (ADR 0042). Every query is limited to one school. */
final class CbtRepository
{
    private const ATTEMPT_SELECT = 'SELECT a.*, u.display_name AS user_name, u.email AS user_email FROM cbt_attempts a JOIN users u ON u.id = a.user_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $f validated exam fields */
    public function createExam(int $tenantId, array $f, int $by): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO cbt_exams (tenant_id, title, instructions, sections, choose_count, duration_minutes, opens_at, closes_at, audience, max_attempts,
                results, review, shuffle_questions, shuffle_options, access_code, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...$this->examValues($f), 'draft', $by, $now, $now],
        );
    }

    /** @param array<string, mixed> $f */
    public function updateExam(int $tenantId, int $id, array $f): void
    {
        $this->db->execute(
            'UPDATE cbt_exams SET title = ?, instructions = ?, sections = ?, choose_count = ?, duration_minutes = ?, opens_at = ?, closes_at = ?, audience = ?,
                max_attempts = ?, results = ?, review = ?, shuffle_questions = ?, shuffle_options = ?, access_code = ?, updated_at = ?
             WHERE tenant_id = ? AND id = ?',
            [...array_slice($this->examValues($f), 1), $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    /**
     * @param array<string, mixed> $f
     * @return list<mixed> tenant id first, then the columns in insert order
     */
    private function examValues(array $f): array
    {
        return [
            $f['tenant_id'], $f['title'], $f['instructions'] === '' ? null : $f['instructions'],
            json_encode($f['sections'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $f['choose_count'], $f['duration_minutes'],
            $f['opens_at'], $f['closes_at'], $f['audience'], $f['max_attempts'], $f['results'], $f['review'] ? 1 : 0,
            $f['shuffle_questions'] ? 1 : 0, $f['shuffle_options'] ? 1 : 0, $f['access_code'],
        ];
    }

    public function setStatus(int $tenantId, int $id, string $status): void
    {
        $this->db->execute('UPDATE cbt_exams SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$status, $this->clock->sqlNow(), $tenantId, $id]);
    }

    /** @param list<int> $groupIds */
    public function setGroups(int $tenantId, int $examId, array $groupIds): void
    {
        $this->db->execute('DELETE FROM cbt_exam_groups WHERE tenant_id = ? AND exam_id = ?', [$tenantId, $examId]);
        foreach (array_values(array_unique($groupIds)) as $g) {
            $this->db->execute('INSERT INTO cbt_exam_groups (tenant_id, exam_id, group_id) VALUES (?, ?, ?)', [$tenantId, $examId, $g]);
        }
    }

    public function findExam(int $tenantId, int $id): ?CbtExam
    {
        $r = $this->db->fetchOne('SELECT * FROM cbt_exams WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $r === null ? null : CbtExam::fromRow($r, $this->groupIds($tenantId, $id));
    }

    /** @return list<int> */
    private function groupIds(int $tenantId, int $examId): array
    {
        return array_map(static fn (array $r): int => (int) $r['group_id'], $this->db->fetchAll(
            'SELECT group_id FROM cbt_exam_groups WHERE tenant_id = ? AND exam_id = ? ORDER BY group_id',
            [$tenantId, $examId],
        ));
    }

    /**
     * Every exam, newest first, with how many attempts each has.
     *
     * @return list<array{exam: CbtExam, attempts: int, submitted: int, in_progress: int}>
     */
    public function exams(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT e.*,
                (SELECT COUNT(*) FROM cbt_attempts a WHERE a.tenant_id = e.tenant_id AND a.exam_id = e.id) AS attempts,
                (SELECT COUNT(*) FROM cbt_attempts a WHERE a.tenant_id = e.tenant_id AND a.exam_id = e.id AND a.submitted_at IS NOT NULL) AS submitted
             FROM cbt_exams e WHERE e.tenant_id = ? ORDER BY e.id DESC LIMIT 200',
            [$tenantId],
        );

        return array_map(fn (array $r): array => [
            'exam' => CbtExam::fromRow($r, $this->groupIds($tenantId, (int) $r['id'])),
            'attempts' => (int) $r['attempts'],
            'submitted' => (int) $r['submitted'],
            'in_progress' => (int) $r['attempts'] - (int) $r['submitted'],
        ], $rows);
    }

    /**
     * Published or closed exams this learner may see: for everyone, or set for one of their classes.
     *
     * @return list<CbtExam>
     */
    public function examsFor(int $tenantId, int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT e.* FROM cbt_exams e
             WHERE e.tenant_id = ? AND e.status IN ('published', 'closed')
               AND (e.audience = 'everyone' OR EXISTS (
                    SELECT 1 FROM cbt_exam_groups g JOIN learner_group_members m ON m.tenant_id = g.tenant_id AND m.group_id = g.group_id
                    WHERE g.tenant_id = e.tenant_id AND g.exam_id = e.id AND m.user_id = ? AND m.role = 'learner'))
             ORDER BY e.id DESC LIMIT 100",
            [$tenantId, $userId],
        );

        return array_map(fn (array $r): CbtExam => CbtExam::fromRow($r, $this->groupIds($tenantId, (int) $r['id'])), $rows);
    }

    public function inGroupOf(int $tenantId, int $examId, int $userId): bool
    {
        return $this->db->fetchValue(
            "SELECT 1 FROM cbt_exam_groups g JOIN learner_group_members m ON m.tenant_id = g.tenant_id AND m.group_id = g.group_id
             WHERE g.tenant_id = ? AND g.exam_id = ? AND m.user_id = ? AND m.role = 'learner' LIMIT 1",
            [$tenantId, $examId, $userId],
        ) !== null;
    }

    /** @return list<array{id: int, name: string}> the school's current classes */
    public function groups(int $tenantId): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $this->db->fetchAll('SELECT id, name FROM learner_groups WHERE tenant_id = ? AND archived_at IS NULL ORDER BY name LIMIT 300', [$tenantId]),
        );
    }

    /**
     * @param array{sections: list<array{label: string, bank: int, questions: list<array{id: int, perm: list<int>}>}>} $paper
     */
    public function createAttempt(int $tenantId, ?int $examId, int $userId, string $title, array $paper, int $questionCount, string $deadline): int
    {
        return $this->db->insert(
            "INSERT INTO cbt_attempts (tenant_id, exam_id, user_id, title, paper, answers, flags, question_count, started_at, deadline_at)
             VALUES (?, ?, ?, ?, ?, '{}', '[]', ?, ?, ?)",
            [$tenantId, $examId, $userId, $title, json_encode($paper, JSON_THROW_ON_ERROR), $questionCount, $this->clock->sqlNow(), $deadline],
        );
    }

    public function findAttempt(int $tenantId, int $id, bool $lock = false): ?CbtAttempt
    {
        $r = $this->db->fetchOne(self::ATTEMPT_SELECT . ' WHERE a.tenant_id = ? AND a.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);

        return $r === null ? null : CbtAttempt::fromRow($r);
    }

    /** @return list<CbtAttempt> one exam's attempts, by candidate name then newest */
    public function attemptsForExam(int $tenantId, int $examId): array
    {
        return array_map(CbtAttempt::fromRow(...), $this->db->fetchAll(
            self::ATTEMPT_SELECT . ' WHERE a.tenant_id = ? AND a.exam_id = ? ORDER BY u.display_name, a.id DESC LIMIT 2000',
            [$tenantId, $examId],
        ));
    }

    /** @return list<CbtAttempt> a learner's attempts at one exam, newest first */
    public function attemptsAt(int $tenantId, int $examId, int $userId): array
    {
        return array_map(CbtAttempt::fromRow(...), $this->db->fetchAll(
            self::ATTEMPT_SELECT . ' WHERE a.tenant_id = ? AND a.exam_id = ? AND a.user_id = ? ORDER BY a.id DESC LIMIT 50',
            [$tenantId, $examId, $userId],
        ));
    }

    /** @return list<CbtAttempt> a learner's practice tests, newest first */
    public function practiceFor(int $tenantId, int $userId, int $limit = 10): array
    {
        return array_map(CbtAttempt::fromRow(...), $this->db->fetchAll(
            self::ATTEMPT_SELECT . ' WHERE a.tenant_id = ? AND a.exam_id IS NULL AND a.user_id = ? ORDER BY a.id DESC LIMIT ' . max(1, $limit),
            [$tenantId, $userId],
        ));
    }

    /**
     * @param array<int, int> $answers
     * @param list<int> $flags
     */
    public function saveAnswers(int $tenantId, int $id, array $answers, array $flags, int $seq): void
    {
        $this->db->execute(
            'UPDATE cbt_attempts SET answers = ?, flags = ?, save_seq = ?, last_saved_at = ? WHERE tenant_id = ? AND id = ? AND submitted_at IS NULL',
            [self::answersJson($answers), json_encode(array_values($flags), JSON_THROW_ON_ERROR), $seq, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    /**
     * @param array{sections: list<array{label: string, correct: int, count: int, scaled: int}>, total: int, max: int} $result
     */
    public function submit(int $tenantId, int $id, string $reason, array $result, int $correct, int $percent, string $at): void
    {
        $this->db->execute(
            'UPDATE cbt_attempts SET submitted_at = ?, submit_reason = ?, result = ?, correct_count = ?, percent = ? WHERE tenant_id = ? AND id = ? AND submitted_at IS NULL',
            [$at, $reason, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $correct, $percent, $tenantId, $id],
        );
    }

    public function extend(int $tenantId, int $id, int $minutes): void
    {
        $this->db->execute(
            'UPDATE cbt_attempts SET deadline_at = DATE_ADD(deadline_at, INTERVAL ? MINUTE), extra_minutes = extra_minutes + ? WHERE tenant_id = ? AND id = ? AND submitted_at IS NULL',
            [$minutes, $minutes, $tenantId, $id],
        );
    }

    public function deleteAttempt(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM cbt_attempts WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /**
     * Attempts past their deadline and grace that nobody submitted, any school.
     *
     * @return list<array{tenant_id: int, id: int}>
     */
    public function overdue(string $before, int $limit = 500): array
    {
        return array_map(
            static fn (array $r): array => ['tenant_id' => (int) $r['tenant_id'], 'id' => (int) $r['id']],
            $this->db->fetchAll('SELECT tenant_id, id FROM cbt_attempts WHERE submitted_at IS NULL AND deadline_at < ? ORDER BY deadline_at LIMIT ' . $limit, [$before]),
        );
    }

    /** @param array<int, int> $answers */
    public static function answersJson(array $answers): string
    {
        $out = [];
        foreach ($answers as $qid => $choice) {
            $out[(string) $qid] = $choice;
        }

        return $out === [] ? '{}' : json_encode($out, JSON_THROW_ON_ERROR);
    }
}
