<?php

declare(strict_types=1);

namespace App\Modules\ReportCards;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Storage for report cards and their remarks (ADR 0046). Tenant-scoped. */
final class ReportCardRepository
{
    private const SELECT = 'SELECT r.*, g.name AS group_name FROM report_cards r JOIN learner_groups g ON g.tenant_id = r.tenant_id AND g.id = r.group_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @param list<array{exam: int, label: string, weight: int}> $components */
    public function create(int $tenantId, int $groupId, string $title, array $components, string $grading, string $nextTerm, int $by): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO report_cards (tenant_id, group_id, title, components, grading, next_term, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $groupId, $title, json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $grading, $nextTerm === '' ? null : $nextTerm, 'draft', $by, $now, $now],
        );
    }

    /** @param list<array{exam: int, label: string, weight: int}> $components */
    public function update(int $tenantId, int $id, string $title, array $components, string $grading, string $nextTerm): void
    {
        $this->db->execute(
            'UPDATE report_cards SET title = ?, components = ?, grading = ?, next_term = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$title, json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $grading, $nextTerm === '' ? null : $nextTerm, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function setStatus(int $tenantId, int $id, string $status): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE report_cards SET status = ?, published_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$status, $status === 'published' ? $now : null, $now, $tenantId, $id],
        );
    }

    public function delete(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM report_cards WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    public function find(int $tenantId, int $id): ?ReportCard
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE r.tenant_id = ? AND r.id = ?', [$tenantId, $id]);

        return $row === null ? null : ReportCard::fromRow($row);
    }

    /** @return list<ReportCard> newest first */
    public function forGroup(int $tenantId, int $groupId): array
    {
        return array_map(ReportCard::fromRow(...), $this->db->fetchAll(self::SELECT . ' WHERE r.tenant_id = ? AND r.group_id = ? ORDER BY r.id DESC LIMIT 100', [$tenantId, $groupId]));
    }

    /**
     * Published cards for the classes this learner belongs to now.
     *
     * @return list<ReportCard>
     */
    public function publishedFor(int $tenantId, int $userId): array
    {
        return array_map(ReportCard::fromRow(...), $this->db->fetchAll(
            self::SELECT . " JOIN learner_group_members m ON m.tenant_id = r.tenant_id AND m.group_id = r.group_id AND m.user_id = ? AND m.role = 'learner'
             WHERE r.tenant_id = ? AND r.status = 'published' ORDER BY r.published_at DESC LIMIT 50",
            [$userId, $tenantId],
        ));
    }

    /** @return array<int, array{teacher: string, head: string}> by learner id */
    public function remarks(int $tenantId, int $cardId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT user_id, teacher_remark, head_remark FROM report_card_remarks WHERE tenant_id = ? AND report_card_id = ?', [$tenantId, $cardId]) as $r) {
            $out[(int) $r['user_id']] = ['teacher' => (string) ($r['teacher_remark'] ?? ''), 'head' => (string) ($r['head_remark'] ?? '')];
        }

        return $out;
    }

    public function saveRemarks(int $tenantId, int $cardId, int $userId, string $teacher, string $head, int $by): void
    {
        $this->db->execute(
            'INSERT INTO report_card_remarks (tenant_id, report_card_id, user_id, teacher_remark, head_remark, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE teacher_remark = VALUES(teacher_remark), head_remark = VALUES(head_remark), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$tenantId, $cardId, $userId, $teacher === '' ? null : $teacher, $head === '' ? null : $head, $by, $this->clock->sqlNow()],
        );
    }

    /**
     * Each learner's latest submitted attempt at each exam, with its scores by subject.
     *
     * @param list<int> $examIds
     * @param list<int> $userIds
     * @return array<int, array<int, array<string, int>>> user id => exam id => subject label => score out of 100
     */
    public function examScores(int $tenantId, array $examIds, array $userIds): array
    {
        if ($examIds === [] || $userIds === []) {
            return [];
        }
        $ex = implode(',', array_fill(0, count($examIds), '?'));
        $us = implode(',', array_fill(0, count($userIds), '?'));
        $out = [];
        foreach (
            $this->db->fetchAll(
                "SELECT a.user_id, a.exam_id, a.result FROM cbt_attempts a
                 JOIN (SELECT user_id, exam_id, MAX(id) AS id FROM cbt_attempts
                       WHERE tenant_id = ? AND exam_id IN ({$ex}) AND user_id IN ({$us}) AND submitted_at IS NOT NULL GROUP BY user_id, exam_id) l ON l.id = a.id",
                [$tenantId, ...$examIds, ...$userIds],
            ) as $r
        ) {
            $result = json_decode((string) $r['result'], true);
            $scores = [];
            foreach (is_array($result['sections'] ?? null) ? $result['sections'] : [] as $s) {
                $scores[(string) ($s['label'] ?? '')] = (int) ($s['scaled'] ?? 0);
            }
            $out[(int) $r['user_id']][(int) $r['exam_id']] = $scores;
        }

        return $out;
    }
}
