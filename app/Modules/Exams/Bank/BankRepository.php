<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Question banks, their passages and questions (ADR 0042). Every query is limited to one school. */
final class BankRepository
{
    private const BANK_SELECT = 'SELECT b.*,
            (SELECT COUNT(*) FROM bank_questions q WHERE q.tenant_id = b.tenant_id AND q.bank_id = b.id AND q.retired_at IS NULL) AS active_count,
            (SELECT COUNT(*) FROM bank_questions q WHERE q.tenant_id = b.tenant_id AND q.bank_id = b.id AND q.retired_at IS NOT NULL) AS retired_count
        FROM question_banks b';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function createBank(int $tenantId, string $name, string $description, bool $practice, int $by): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO question_banks (tenant_id, name, description, practice, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $name, $description, $practice ? 1 : 0, $by, $now, $now],
        );
    }

    public function updateBank(int $tenantId, int $id, string $name, string $description, bool $practice): void
    {
        $this->db->execute(
            'UPDATE question_banks SET name = ?, description = ?, practice = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$name, $description, $practice ? 1 : 0, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function setArchived(int $tenantId, int $id, bool $archived): void
    {
        $this->db->execute(
            'UPDATE question_banks SET archived_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$archived ? $this->clock->sqlNow() : null, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function touchBank(int $tenantId, int $id): void
    {
        $this->db->execute('UPDATE question_banks SET updated_at = ? WHERE tenant_id = ? AND id = ?', [$this->clock->sqlNow(), $tenantId, $id]);
    }

    /** @return list<QuestionBank> by name; archived ones only when asked */
    public function banks(int $tenantId, bool $withArchived = false, bool $practiceOnly = false): array
    {
        $where = ' WHERE b.tenant_id = ?' . ($withArchived ? '' : ' AND b.archived_at IS NULL') . ($practiceOnly ? ' AND b.practice = 1' : '');

        return array_map(QuestionBank::fromRow(...), $this->db->fetchAll(self::BANK_SELECT . $where . ' ORDER BY b.archived_at IS NOT NULL, b.name LIMIT 200', [$tenantId]));
    }

    public function findBank(int $tenantId, int $id): ?QuestionBank
    {
        $r = $this->db->fetchOne(self::BANK_SELECT . ' WHERE b.tenant_id = ? AND b.id = ?', [$tenantId, $id]);

        return $r === null ? null : QuestionBank::fromRow($r);
    }

    public function nameTaken(int $tenantId, string $name, int $exceptId = 0): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM question_banks WHERE tenant_id = ? AND name = ? AND id <> ?', [$tenantId, $name, $exceptId]) !== null;
    }

    /**
     * A page of questions in a bank.
     *
     * @return array{0: list<BankQuestion>, 1: int} questions and the total that match
     */
    public function questions(int $tenantId, int $bankId, string $find = '', string $topic = '', string $status = 'active', int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE tenant_id = ? AND bank_id = ?';
        $params = [$tenantId, $bankId];
        if ($status === 'active') {
            $where .= ' AND retired_at IS NULL';
        } elseif ($status === 'retired') {
            $where .= ' AND retired_at IS NOT NULL';
        }
        if ($find !== '') {
            $where .= ' AND prompt LIKE ?';
            $params[] = '%' . addcslashes($find, '%_\\') . '%';
        }
        if ($topic !== '') {
            $where .= ' AND topic = ?';
            $params[] = $topic;
        }
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM bank_questions' . $where, $params);
        $rows = $this->db->fetchAll(
            'SELECT * FROM bank_questions' . $where . ' ORDER BY id LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $params,
        );

        return [array_map(BankQuestion::fromRow(...), $rows), $total];
    }

    /** @return list<BankQuestion> every question of a bank (for export), oldest first */
    public function allQuestions(int $tenantId, int $bankId, bool $withRetired = false): array
    {
        return array_map(BankQuestion::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM bank_questions WHERE tenant_id = ? AND bank_id = ?' . ($withRetired ? '' : ' AND retired_at IS NULL') . ' ORDER BY id LIMIT 5000',
            [$tenantId, $bankId],
        ));
    }

    public function findQuestion(int $tenantId, int $id): ?BankQuestion
    {
        $r = $this->db->fetchOne('SELECT * FROM bank_questions WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $r === null ? null : BankQuestion::fromRow($r);
    }

    /**
     * @param list<int> $ids
     * @return array<int, BankQuestion> by id
     */
    public function questionsByIds(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $rows = $this->db->fetchAll(
                'SELECT * FROM bank_questions WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [$tenantId, ...$chunk],
            );
            foreach ($rows as $r) {
                $q = BankQuestion::fromRow($r);
                $out[$q->id] = $q;
            }
        }

        return $out;
    }

    /**
     * Questions that can be drawn into a paper: id, passage id and how many options, oldest first.
     *
     * @return list<array{id: int, passage: ?int, options: int}>
     */
    public function drawable(int $tenantId, int $bankId): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'passage' => isset($r['passage_id']) ? (int) $r['passage_id'] : null, 'options' => (int) $r['n']],
            $this->db->fetchAll(
                'SELECT id, passage_id, JSON_LENGTH(options) AS n FROM bank_questions WHERE tenant_id = ? AND bank_id = ? AND retired_at IS NULL ORDER BY id',
                [$tenantId, $bankId],
            ),
        );
    }

    public function drawableCount(int $tenantId, int $bankId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM bank_questions WHERE tenant_id = ? AND bank_id = ? AND retired_at IS NULL', [$tenantId, $bankId]);
    }

    /** @return list<string> topics used in a bank, A to Z */
    public function topics(int $tenantId, int $bankId): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['topic'],
            $this->db->fetchAll("SELECT DISTINCT topic FROM bank_questions WHERE tenant_id = ? AND bank_id = ? AND topic <> '' ORDER BY topic LIMIT 200", [$tenantId, $bankId]),
        );
    }

    /**
     * @param array{passage_id: ?int, prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int} $q
     */
    public function createQuestion(int $tenantId, int $bankId, array $q, int $by): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO bank_questions (tenant_id, bank_id, passage_id, prompt, options, correct, explanation, topic, difficulty, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId, $bankId, $q['passage_id'], $q['prompt'], json_encode($q['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $q['correct'], $q['explanation'] === '' ? null : $q['explanation'], $q['topic'], $q['difficulty'], $by, $now, $now,
            ],
        );
    }

    /**
     * @param array{passage_id: ?int, prompt: string, options: list<string>, correct: int, explanation: string, topic: string, difficulty: ?int} $q
     */
    public function updateQuestion(int $tenantId, int $id, array $q): void
    {
        $this->db->execute(
            'UPDATE bank_questions SET passage_id = ?, prompt = ?, options = ?, correct = ?, explanation = ?, topic = ?, difficulty = ?, updated_at = ?
             WHERE tenant_id = ? AND id = ?',
            [
                $q['passage_id'], $q['prompt'], json_encode($q['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $q['correct'],
                $q['explanation'] === '' ? null : $q['explanation'], $q['topic'], $q['difficulty'], $this->clock->sqlNow(), $tenantId, $id,
            ],
        );
    }

    public function setImage(int $tenantId, int $id, ?int $fileId, ?string $alt): void
    {
        $this->db->execute(
            'UPDATE bank_questions SET image_file_id = ?, image_alt = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$fileId, $alt, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    /**
     * Whether this learner has the question in one of their papers (exam, practice or trial).
     * Looks through their most recent 200 attempts.
     */
    public function inPaperOf(int $tenantId, int $userId, int $questionId): bool
    {
        foreach ($this->db->fetchAll('SELECT paper FROM cbt_attempts WHERE tenant_id = ? AND user_id = ? ORDER BY id DESC LIMIT 200', [$tenantId, $userId]) as $r) {
            $paper = json_decode((string) $r['paper'], true);
            foreach (is_array($paper['sections'] ?? null) ? $paper['sections'] : [] as $s) {
                foreach (is_array($s['questions'] ?? null) ? $s['questions'] : [] as $q) {
                    if ((int) ($q['id'] ?? 0) === $questionId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function setRetired(int $tenantId, int $id, bool $retired): void
    {
        $this->db->execute(
            'UPDATE bank_questions SET retired_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$retired ? $this->clock->sqlNow() : null, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    /** @return list<Passage> */
    public function passages(int $tenantId, int $bankId): array
    {
        return array_map(Passage::fromRow(...), $this->db->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM bank_questions q WHERE q.passage_id = p.id AND q.retired_at IS NULL) AS question_count
             FROM bank_passages p WHERE p.tenant_id = ? AND p.bank_id = ? ORDER BY p.id LIMIT 200',
            [$tenantId, $bankId],
        ));
    }

    public function findPassage(int $tenantId, int $id): ?Passage
    {
        $r = $this->db->fetchOne('SELECT * FROM bank_passages WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $r === null ? null : Passage::fromRow($r);
    }

    /**
     * @param list<int> $ids
     * @return array<int, Passage>
     */
    public function passagesByIds(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = $this->db->fetchAll(
            'SELECT * FROM bank_passages WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$tenantId, ...$ids],
        );
        foreach ($rows as $r) {
            $p = Passage::fromRow($r);
            $out[$p->id] = $p;
        }

        return $out;
    }

    public function createPassage(int $tenantId, int $bankId, string $title, string $body): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO bank_passages (tenant_id, bank_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $bankId, $title, $body, $now, $now],
        );
    }

    public function updatePassage(int $tenantId, int $id, string $title, string $body): void
    {
        $this->db->execute(
            'UPDATE bank_passages SET title = ?, body = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$title, $body, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function deletePassage(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM bank_passages WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /**
     * Counts what candidates did with each question in a submitted attempt.
     *
     * @param array<int, bool> $results question id => answered correctly
     */
    public function recordResults(int $tenantId, array $results): void
    {
        foreach ([[true, array_keys(array_filter($results))], [false, array_keys(array_filter($results, static fn (bool $ok): bool => !$ok))]] as [$right, $ids]) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $this->db->execute(
                    'UPDATE bank_questions SET times_seen = times_seen + 1' . ($right ? ', times_correct = times_correct + 1' : '')
                    . ' WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                    [$tenantId, ...$chunk],
                );
            }
        }
    }
}
