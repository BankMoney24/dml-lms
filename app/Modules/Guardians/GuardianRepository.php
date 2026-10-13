<?php

declare(strict_types=1);

namespace App\Modules\Guardians;

use App\Core\Clock;
use App\Core\Database\Connection;

final class GuardianRepository
{
    private const SELECT = "SELECT gl.*, g.display_name AS guardian_name, g.email AS guardian_email, l.display_name AS learner_name
        FROM guardian_links gl JOIN users g ON g.id = gl.guardian_id JOIN users l ON l.id = gl.learner_id";

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** Creates the link, or brings back a revoked one with the new details. */
    public function link(int $tenantId, int $guardianId, int $learnerId, string $relationship, bool $progress, bool $results, bool $notices, int $verifiedBy): void
    {
        $this->db->execute(
            "INSERT INTO guardian_links (tenant_id, guardian_id, learner_id, relationship, can_progress, can_results, can_notices, status, verified_by, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE relationship = VALUES(relationship), can_progress = VALUES(can_progress), can_results = VALUES(can_results),
                 can_notices = VALUES(can_notices), status = 'active', verified_by = VALUES(verified_by), verified_at = VALUES(verified_at),
                 revoked_by = NULL, revoked_at = NULL",
            [$tenantId, $guardianId, $learnerId, $relationship, (int) $progress, (int) $results, (int) $notices, $verifiedBy, $this->clock->sqlNow()],
        );
    }

    public function find(int $tenantId, int $linkId): ?GuardianLink
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE gl.tenant_id = ? AND gl.id = ?', [$tenantId, $linkId]);

        return $row === null ? null : GuardianLink::fromRow($row);
    }

    /** The active link between this guardian and this learner, if any. Only active school members count. */
    public function active(int $tenantId, int $guardianId, int $learnerId): ?GuardianLink
    {
        $row = $this->db->fetchOne(
            self::SELECT . " JOIN memberships mg ON mg.tenant_id = gl.tenant_id AND mg.user_id = gl.guardian_id AND mg.status = 'active'
             JOIN memberships ml ON ml.tenant_id = gl.tenant_id AND ml.user_id = gl.learner_id AND ml.status = 'active'
             WHERE gl.tenant_id = ? AND gl.guardian_id = ? AND gl.learner_id = ? AND gl.status = 'active'",
            [$tenantId, $guardianId, $learnerId],
        );

        return $row === null ? null : GuardianLink::fromRow($row);
    }

    /** @return list<GuardianLink> the learners this guardian may see */
    public function childrenOf(int $tenantId, int $guardianId): array
    {
        return array_map(static fn (array $r): GuardianLink => GuardianLink::fromRow($r), $this->db->fetchAll(
            self::SELECT . " JOIN memberships ml ON ml.tenant_id = gl.tenant_id AND ml.user_id = gl.learner_id AND ml.status = 'active'
             WHERE gl.tenant_id = ? AND gl.guardian_id = ? AND gl.status = 'active' ORDER BY l.display_name LIMIT 50",
            [$tenantId, $guardianId],
        ));
    }

    /** @return list<GuardianLink> everyone linked to this learner, active first */
    public function guardiansOf(int $tenantId, int $learnerId): array
    {
        return array_map(static fn (array $r): GuardianLink => GuardianLink::fromRow($r), $this->db->fetchAll(
            self::SELECT . " WHERE gl.tenant_id = ? AND gl.learner_id = ? ORDER BY gl.status = 'active' DESC, g.display_name LIMIT 50",
            [$tenantId, $learnerId],
        ));
    }

    /**
     * Guardians who should hear a class notice for any of these learners.
     *
     * @param list<int> $learnerIds
     * @return list<array{guardian_id: int, learner_name: string}>
     */
    public function noticeRecipients(int $tenantId, array $learnerIds): array
    {
        if ($learnerIds === []) {
            return [];
        }

        return array_map(static fn (array $r): array => ['guardian_id' => (int) $r['guardian_id'], 'learner_name' => (string) $r['learner_name']], $this->db->fetchAll(
            "SELECT gl.guardian_id, l.display_name AS learner_name FROM guardian_links gl JOIN users l ON l.id = gl.learner_id
             WHERE gl.tenant_id = ? AND gl.status = 'active' AND gl.can_notices = 1 AND gl.learner_id IN (" . implode(',', array_fill(0, count($learnerIds), '?')) . ')',
            array_merge([$tenantId], $learnerIds),
        ));
    }

    /** @return list<int> guardians allowed to follow this learner's progress */
    public function progressRecipients(int $tenantId, int $learnerId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT guardian_id FROM guardian_links WHERE tenant_id = ? AND learner_id = ? AND status = 'active' AND can_progress = 1",
            [$tenantId, $learnerId],
        ), 'guardian_id'));
    }

    public function setPermissions(int $tenantId, int $linkId, bool $progress, bool $results, bool $notices): void
    {
        $this->db->execute(
            'UPDATE guardian_links SET can_progress = ?, can_results = ?, can_notices = ? WHERE tenant_id = ? AND id = ?',
            [(int) $progress, (int) $results, (int) $notices, $tenantId, $linkId],
        );
    }

    public function revoke(int $tenantId, int $linkId, int $by): void
    {
        $this->db->execute(
            "UPDATE guardian_links SET status = 'revoked', revoked_by = ?, revoked_at = ? WHERE tenant_id = ? AND id = ? AND status = 'active'",
            [$by, $this->clock->sqlNow(), $tenantId, $linkId],
        );
    }

    /** @return array<int, int> learner id => number of active guardians, for the People page */
    public function counts(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT learner_id, COUNT(*) AS n FROM guardian_links WHERE tenant_id = ? AND status = 'active' GROUP BY learner_id", [$tenantId]) as $r) {
            $out[(int) $r['learner_id']] = (int) $r['n'];
        }

        return $out;
    }
}
