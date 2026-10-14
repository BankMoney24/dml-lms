<?php

declare(strict_types=1);

namespace App\Modules\Discussions;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Lesson questions and their answers. Every query is scoped by school. */
final class QuestionRepository
{
    private const QUESTION_SELECT = 'SELECT q.*, u.display_name AS author_name, c.title AS course_title, c.slug AS course_slug, l.title AS lesson_title
        FROM lesson_questions q
        JOIN users u ON u.id = q.user_id
        JOIN courses c ON c.tenant_id = q.tenant_id AND c.id = q.course_id
        JOIN lessons l ON l.tenant_id = q.tenant_id AND l.id = q.lesson_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $courseId, int $lessonId, int $userId, string $body): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO lesson_questions (tenant_id, course_id, lesson_id, user_id, body, status, last_activity_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $courseId, $lessonId, $userId, $body, Question::OPEN, $now, $now, $now],
        );
    }

    public function find(int $tenantId, int $questionId): ?Question
    {
        $row = $this->db->fetchOne(self::QUESTION_SELECT . ' WHERE q.tenant_id = ? AND q.id = ?', [$tenantId, $questionId]);

        return $row === null ? null : Question::fromRow($row)->withAnswers($this->answersFor($tenantId, [(int) $row['id']], true)[(int) $row['id']] ?? []);
    }

    /**
     * Open questions on a lesson with their visible answers, newest activity first.
     *
     * @return list<Question>
     */
    public function forLesson(int $tenantId, int $lessonId, int $limit = 30): array
    {
        $rows = $this->db->fetchAll(
            self::QUESTION_SELECT . ' WHERE q.tenant_id = ? AND q.lesson_id = ? AND q.status = ? ORDER BY q.last_activity_at DESC, q.id DESC LIMIT ' . max(1, min($limit, 100)),
            [$tenantId, $lessonId, Question::OPEN],
        );

        return $this->attach($tenantId, $rows, false);
    }

    public function countForLesson(int $tenantId, int $lessonId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM lesson_questions WHERE tenant_id = ? AND lesson_id = ? AND status = ?', [$tenantId, $lessonId, Question::OPEN]);
    }

    /**
     * The teachers' inbox: questions across the school. "waiting" = no teacher has
     * answered and no answer was accepted; "hidden" = what teachers hid.
     *
     * @return list<Question>
     */
    public function inbox(int $tenantId, string $filter, int $limit = 50): array
    {
        $where = match ($filter) {
            'hidden' => "q.status = 'hidden'",
            'all' => "q.status = 'open'",
            default => "q.status = 'open' AND q.staff_answered = 0 AND q.accepted_answer_id IS NULL",
        };
        $rows = $this->db->fetchAll(
            self::QUESTION_SELECT . ' WHERE q.tenant_id = ? AND ' . $where
                . ' ORDER BY q.last_activity_at ' . ($filter === 'waiting' ? 'ASC' : 'DESC') . ', q.id DESC LIMIT ' . max(1, min($limit, 200)),
            [$tenantId],
        );

        return $this->attach($tenantId, $rows, true);
    }

    public function waitingCount(int $tenantId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM lesson_questions WHERE tenant_id = ? AND status = 'open' AND staff_answered = 0 AND accepted_answer_id IS NULL",
            [$tenantId],
        );
    }

    public function addAnswer(int $tenantId, int $questionId, int $userId, string $body, bool $byStaff): int
    {
        $now = $this->clock->sqlNow();
        $id = $this->db->insert(
            'INSERT INTO lesson_answers (tenant_id, question_id, user_id, body, by_staff, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $questionId, $userId, $body, $byStaff ? 1 : 0, Answer::VISIBLE, $now, $now],
        );
        $this->refreshCounts($tenantId, $questionId);
        $this->db->execute('UPDATE lesson_questions SET last_activity_at = ? WHERE tenant_id = ? AND id = ?', [$now, $tenantId, $questionId]);

        return $id;
    }

    public function findAnswer(int $tenantId, int $answerId): ?Answer
    {
        $row = $this->db->fetchOne(
            'SELECT a.*, u.display_name AS author_name FROM lesson_answers a JOIN users u ON u.id = a.user_id WHERE a.tenant_id = ? AND a.id = ?',
            [$tenantId, $answerId],
        );

        return $row === null ? null : Answer::fromRow($row);
    }

    public function accept(int $tenantId, int $questionId, ?int $answerId): void
    {
        $this->db->execute(
            'UPDATE lesson_questions SET accepted_answer_id = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$answerId, $this->clock->sqlNow(), $tenantId, $questionId],
        );
    }

    public function setQuestionStatus(int $tenantId, int $questionId, string $status): void
    {
        $this->db->execute('UPDATE lesson_questions SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$status, $this->clock->sqlNow(), $tenantId, $questionId]);
    }

    public function setAnswerStatus(int $tenantId, int $answerId, string $status): void
    {
        $answer = $this->findAnswer($tenantId, $answerId);
        if ($answer === null) {
            return;
        }
        $this->db->execute('UPDATE lesson_answers SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$status, $this->clock->sqlNow(), $tenantId, $answerId]);
        if ($status !== Answer::VISIBLE) {
            // An answer that disappears cannot stay the accepted one.
            $this->db->execute('UPDATE lesson_questions SET accepted_answer_id = NULL WHERE tenant_id = ? AND id = ? AND accepted_answer_id = ?', [$tenantId, $answer->questionId, $answerId]);
        }
        $this->refreshCounts($tenantId, $answer->questionId);
    }

    /** Recent questions by one person on one lesson, to stop the same question being posted twice. */
    public function recentSameBody(int $tenantId, int $lessonId, int $userId, string $body): bool
    {
        return $this->db->fetchValue(
            "SELECT 1 FROM lesson_questions WHERE tenant_id = ? AND lesson_id = ? AND user_id = ? AND body = ? AND status = 'open' LIMIT 1",
            [$tenantId, $lessonId, $userId, $body],
        ) !== null;
    }

    private function refreshCounts(int $tenantId, int $questionId): void
    {
        $this->db->execute(
            "UPDATE lesson_questions q SET
                answer_count = (SELECT COUNT(*) FROM lesson_answers a WHERE a.tenant_id = q.tenant_id AND a.question_id = q.id AND a.status = 'visible'),
                staff_answered = (SELECT COUNT(*) > 0 FROM lesson_answers a WHERE a.tenant_id = q.tenant_id AND a.question_id = q.id AND a.status = 'visible' AND a.by_staff = 1)
             WHERE q.tenant_id = ? AND q.id = ?",
            [$tenantId, $questionId],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Question>
     */
    private function attach(int $tenantId, array $rows, bool $includeHidden): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $answers = $this->answersFor($tenantId, $ids, $includeHidden);

        return array_map(static fn (array $r): Question => Question::fromRow($r)->withAnswers($answers[(int) $r['id']] ?? []), $rows);
    }

    /**
     * @param list<int> $questionIds
     * @return array<int, list<Answer>>
     */
    private function answersFor(int $tenantId, array $questionIds, bool $includeHidden): array
    {
        if ($questionIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT a.*, u.display_name AS author_name FROM lesson_answers a JOIN users u ON u.id = a.user_id
             WHERE a.tenant_id = ? AND a.question_id IN (' . implode(',', array_fill(0, count($questionIds), '?')) . ')
             AND a.status ' . ($includeHidden ? "IN ('visible', 'hidden')" : "= 'visible'") . ' ORDER BY a.id',
            [$tenantId, ...$questionIds],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['question_id']][] = Answer::fromRow($row);
        }

        return $out;
    }
}
