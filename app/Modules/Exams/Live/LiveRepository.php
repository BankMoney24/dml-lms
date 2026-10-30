<?php

declare(strict_types=1);

namespace App\Modules\Exams\Live;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Live quizzes, their runs, players and answers (ADR 0042). Every query is limited to one school. */
final class LiveRepository
{
    private const QUIZ_SELECT = 'SELECT z.*, u.display_name AS creator_name,
            (SELECT COUNT(*) FROM live_questions q WHERE q.tenant_id = z.tenant_id AND q.quiz_id = z.id) AS question_count,
            (SELECT COUNT(*) FROM live_sessions s WHERE s.tenant_id = z.tenant_id AND s.quiz_id = z.id) AS runs
        FROM live_quizzes z JOIN users u ON u.id = z.created_by';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function createQuiz(int $tenantId, string $title, int $by): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert('INSERT INTO live_quizzes (tenant_id, title, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$tenantId, $title, $by, $now, $now]);
    }

    public function renameQuiz(int $tenantId, int $id, string $title): void
    {
        $this->db->execute('UPDATE live_quizzes SET title = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$title, $this->clock->sqlNow(), $tenantId, $id]);
    }

    public function touchQuiz(int $tenantId, int $id): void
    {
        $this->db->execute('UPDATE live_quizzes SET updated_at = ? WHERE tenant_id = ? AND id = ?', [$this->clock->sqlNow(), $tenantId, $id]);
    }

    public function setArchived(int $tenantId, int $id, bool $archived): void
    {
        $this->db->execute('UPDATE live_quizzes SET archived_at = ? WHERE tenant_id = ? AND id = ?', [$archived ? $this->clock->sqlNow() : null, $tenantId, $id]);
    }

    /** @return list<LiveQuiz> newest change first */
    public function quizzes(int $tenantId, bool $archived = false): array
    {
        return array_map(LiveQuiz::fromRow(...), $this->db->fetchAll(
            self::QUIZ_SELECT . ' WHERE z.tenant_id = ? AND z.archived_at IS ' . ($archived ? 'NOT ' : '') . 'NULL ORDER BY z.updated_at DESC, z.id DESC LIMIT 200',
            [$tenantId],
        ));
    }

    public function findQuiz(int $tenantId, int $id): ?LiveQuiz
    {
        $r = $this->db->fetchOne(self::QUIZ_SELECT . ' WHERE z.tenant_id = ? AND z.id = ?', [$tenantId, $id]);

        return $r === null ? null : LiveQuiz::fromRow($r);
    }

    /** @return list<LiveQuestion> in order */
    public function questions(int $tenantId, int $quizId): array
    {
        return array_map(LiveQuestion::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM live_questions WHERE tenant_id = ? AND quiz_id = ? ORDER BY position, id LIMIT 200',
            [$tenantId, $quizId],
        ));
    }

    public function findQuestion(int $tenantId, int $quizId, int $id): ?LiveQuestion
    {
        $r = $this->db->fetchOne('SELECT * FROM live_questions WHERE tenant_id = ? AND quiz_id = ? AND id = ?', [$tenantId, $quizId, $id]);

        return $r === null ? null : LiveQuestion::fromRow($r);
    }

    /** @param array{prompt: string, options: list<string>, correct: int, seconds: int, points: string} $q */
    public function addQuestion(int $tenantId, int $quizId, array $q): int
    {
        $position = (int) $this->db->fetchValue('SELECT COALESCE(MAX(position), 0) + 1 FROM live_questions WHERE tenant_id = ? AND quiz_id = ?', [$tenantId, $quizId]);

        return $this->db->insert(
            'INSERT INTO live_questions (tenant_id, quiz_id, position, prompt, options, correct, seconds, points) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $quizId, $position, $q['prompt'], json_encode($q['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $q['correct'], $q['seconds'], $q['points']],
        );
    }

    /** @param array{prompt: string, options: list<string>, correct: int, seconds: int, points: string} $q */
    public function updateQuestion(int $tenantId, int $id, array $q): void
    {
        $this->db->execute(
            'UPDATE live_questions SET prompt = ?, options = ?, correct = ?, seconds = ?, points = ? WHERE tenant_id = ? AND id = ?',
            [$q['prompt'], json_encode($q['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $q['correct'], $q['seconds'], $q['points'], $tenantId, $id],
        );
    }

    public function deleteQuestion(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM live_questions WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @param list<int> $ids question ids in their new order */
    public function reorder(int $tenantId, int $quizId, array $ids): void
    {
        foreach ($ids as $i => $id) {
            $this->db->execute('UPDATE live_questions SET position = ? WHERE tenant_id = ? AND quiz_id = ? AND id = ?', [$i + 1, $tenantId, $quizId, $id]);
        }
    }

    /** @param list<array{prompt: string, options: list<string>, correct: int, seconds: int, points: string}> $questions */
    public function createSession(int $tenantId, int $quizId, int $hostId, string $pin, string $title, array $questions, bool $guests, bool $funNames): int
    {
        return $this->db->insert(
            'INSERT INTO live_sessions (tenant_id, quiz_id, host_id, pin, title, questions, allow_guests, fun_names, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $quizId, $hostId, $pin, $title, json_encode($questions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $guests ? 1 : 0, $funNames ? 1 : 0, $this->clock->sqlNow()],
        );
    }

    public function pinInUse(int $tenantId, string $pin): bool
    {
        return $this->db->fetchValue("SELECT 1 FROM live_sessions WHERE tenant_id = ? AND pin = ? AND status <> 'finished'", [$tenantId, $pin]) !== null;
    }

    public function findSession(int $tenantId, int $id, bool $lock = false): ?LiveSession
    {
        $r = $this->db->fetchOne('SELECT * FROM live_sessions WHERE tenant_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);

        return $r === null ? null : LiveSession::fromRow($r);
    }

    /**
     * The cheapest look at a session.
     *
     * @return ?array{status: string, version: int, position: ?int}
     */
    public function sessionVersion(int $tenantId, int $id): ?array
    {
        $r = $this->db->fetchOne('SELECT status, version, position, question_ends_at FROM live_sessions WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $r === null ? null : ['status' => (string) $r['status'], 'version' => (int) $r['version'], 'position' => isset($r['position']) ? (int) $r['position'] : null];
    }

    public function findOpenByPin(int $tenantId, string $pin): ?LiveSession
    {
        $r = $this->db->fetchOne("SELECT * FROM live_sessions WHERE tenant_id = ? AND pin = ? AND status <> 'finished' ORDER BY id DESC LIMIT 1", [$tenantId, $pin]);

        return $r === null ? null : LiveSession::fromRow($r);
    }

    /** @return list<LiveSession> runs of a quiz, newest first */
    public function sessionsFor(int $tenantId, int $quizId): array
    {
        return array_map(LiveSession::fromRow(...), $this->db->fetchAll('SELECT * FROM live_sessions WHERE tenant_id = ? AND quiz_id = ? ORDER BY id DESC LIMIT 50', [$tenantId, $quizId]));
    }

    public function setState(int $tenantId, int $id, string $status, ?int $position, ?string $startedAt, ?string $endsAt): void
    {
        $this->db->execute(
            'UPDATE live_sessions SET status = ?, position = ?, question_started_at = ?, question_ends_at = ?, version = version + 1,
                ended_at = ' . ($status === 'finished' ? '?' : 'ended_at') . ' WHERE tenant_id = ? AND id = ?',
            $status === 'finished'
                ? [$status, $position, $startedAt, $endsAt, $this->clock->sqlNow(), $tenantId, $id]
                : [$status, $position, $startedAt, $endsAt, $tenantId, $id],
        );
    }

    /**
     * Sessions nobody finished, older than $before (any school).
     *
     * @return list<array{tenant_id: int, id: int}>
     */
    public function stale(string $before): array
    {
        return array_map(
            static fn (array $r): array => ['tenant_id' => (int) $r['tenant_id'], 'id' => (int) $r['id']],
            $this->db->fetchAll("SELECT tenant_id, id FROM live_sessions WHERE status <> 'finished' AND created_at < ? LIMIT 500", [$before]),
        );
    }

    public function addPlayer(int $tenantId, int $sessionId, ?int $userId, string $nickname): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO live_players (tenant_id, session_id, user_id, nickname, joined_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $sessionId, $userId, $nickname, $now, $now],
        );
    }

    public function findPlayer(int $tenantId, int $sessionId, int $id): ?LivePlayer
    {
        $r = $this->db->fetchOne('SELECT * FROM live_players WHERE tenant_id = ? AND session_id = ? AND id = ?', [$tenantId, $sessionId, $id]);

        return $r === null ? null : LivePlayer::fromRow($r);
    }

    public function playerForUser(int $tenantId, int $sessionId, int $userId): ?LivePlayer
    {
        $r = $this->db->fetchOne('SELECT * FROM live_players WHERE tenant_id = ? AND session_id = ? AND user_id = ?', [$tenantId, $sessionId, $userId]);

        return $r === null ? null : LivePlayer::fromRow($r);
    }

    public function nicknameTaken(int $sessionId, string $nickname): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM live_players WHERE session_id = ? AND nickname = ?', [$sessionId, $nickname]) !== null;
    }

    /** @return list<LivePlayer> players still in the game, best first */
    public function players(int $tenantId, int $sessionId, bool $withRemoved = false): array
    {
        return array_map(LivePlayer::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM live_players WHERE tenant_id = ? AND session_id = ?' . ($withRemoved ? '' : ' AND removed_at IS NULL')
            . ' ORDER BY score DESC, correct_count DESC, id LIMIT 500',
            [$tenantId, $sessionId],
        ));
    }

    public function activeCount(int $tenantId, int $sessionId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM live_players WHERE tenant_id = ? AND session_id = ? AND removed_at IS NULL', [$tenantId, $sessionId]);
    }

    public function removePlayer(int $tenantId, int $sessionId, int $id): void
    {
        $this->db->execute('UPDATE live_players SET removed_at = ? WHERE tenant_id = ? AND session_id = ? AND id = ?', [$this->clock->sqlNow(), $tenantId, $sessionId, $id]);
    }

    /** Records when a player first saw a question, and that they are still here. */
    public function seen(int $tenantId, int $playerId, ?int $position, ?string $at): void
    {
        if ($position !== null && $at !== null) {
            $this->db->execute(
                'UPDATE live_players SET seen_position = ?, seen_at = ?, last_seen_at = ? WHERE tenant_id = ? AND id = ? AND (seen_position IS NULL OR seen_position <> ?)',
                [$position, $at, $this->clock->sqlNow(), $tenantId, $playerId, $position],
            );

            return;
        }
        $this->db->execute('UPDATE live_players SET last_seen_at = ? WHERE tenant_id = ? AND id = ?', [$this->clock->sqlNow(), $tenantId, $playerId]);
    }

    /** False when this player already answered this question. */
    public function addAnswer(int $tenantId, int $sessionId, int $playerId, int $position, int $choice, bool $correct, int $points, int $ms, string $at): bool
    {
        $done = $this->db->execute(
            'INSERT IGNORE INTO live_answers (tenant_id, session_id, player_id, position, choice, correct, points, ms, answered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $sessionId, $playerId, $position, $choice, $correct ? 1 : 0, $points, $ms, $at],
        );

        return $done === 1;
    }

    public function scorePlayer(int $tenantId, int $playerId, int $points, bool $correct): void
    {
        $this->db->execute(
            'UPDATE live_players SET score = score + ?, streak = ' . ($correct ? 'streak + 1' : '0') . ', correct_count = correct_count + ? WHERE tenant_id = ? AND id = ?',
            [$points, $correct ? 1 : 0, $tenantId, $playerId],
        );
    }

    /** Players who did not answer a question lose their streak. */
    public function breakStreaksOfSilent(int $tenantId, int $sessionId, int $position): void
    {
        $this->db->execute(
            'UPDATE live_players p SET streak = 0 WHERE p.tenant_id = ? AND p.session_id = ? AND NOT EXISTS (
                SELECT 1 FROM live_answers a WHERE a.player_id = p.id AND a.position = ?)',
            [$tenantId, $sessionId, $position],
        );
    }

    public function answeredCount(int $tenantId, int $sessionId, int $position): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM live_answers a JOIN live_players p ON p.id = a.player_id AND p.removed_at IS NULL WHERE a.tenant_id = ? AND a.session_id = ? AND a.position = ?',
            [$tenantId, $sessionId, $position],
        );
    }

    /** @return ?array{choice: int, correct: bool, points: int} */
    public function answerOf(int $playerId, int $position): ?array
    {
        $r = $this->db->fetchOne('SELECT choice, correct, points FROM live_answers WHERE player_id = ? AND position = ?', [$playerId, $position]);

        return $r === null ? null : ['choice' => (int) $r['choice'], 'correct' => (bool) $r['correct'], 'points' => (int) $r['points']];
    }

    /** @return array<int, int> choice => how many picked it */
    public function distribution(int $tenantId, int $sessionId, int $position): array
    {
        $out = [];
        $rows = $this->db->fetchAll(
            'SELECT a.choice, COUNT(*) AS n FROM live_answers a JOIN live_players p ON p.id = a.player_id AND p.removed_at IS NULL
             WHERE a.tenant_id = ? AND a.session_id = ? AND a.position = ? GROUP BY a.choice',
            [$tenantId, $sessionId, $position],
        );
        foreach ($rows as $r) {
            $out[(int) $r['choice']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return list<array{player_id: int, position: int, choice: int, correct: bool, points: int, ms: int}> */
    public function answers(int $tenantId, int $sessionId): array
    {
        return array_map(static fn (array $r): array => [
            'player_id' => (int) $r['player_id'], 'position' => (int) $r['position'], 'choice' => (int) $r['choice'],
            'correct' => (bool) $r['correct'], 'points' => (int) $r['points'], 'ms' => (int) $r['ms'],
        ], $this->db->fetchAll('SELECT * FROM live_answers WHERE tenant_id = ? AND session_id = ? ORDER BY position, id', [$tenantId, $sessionId]));
    }
}
