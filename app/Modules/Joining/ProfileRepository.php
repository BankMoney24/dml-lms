<?php

declare(strict_types=1);

namespace App\Modules\Joining;

use App\Core\Clock;
use App\Core\Database\Connection;

final class ProfileRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, int $userId): ?LearnerProfile
    {
        $row = $this->db->fetchOne('SELECT * FROM learner_profiles WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);

        return $row === null ? null : LearnerProfile::fromRow($row);
    }

    /**
     * @param list<int> $userIds
     * @return array<int, LearnerProfile>
     */
    public function many(int $tenantId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM learner_profiles WHERE tenant_id = ? AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')',
            [$tenantId, ...$userIds],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']] = LearnerProfile::fromRow($row);
        }

        return $out;
    }

    /** Someone who just signed themselves up, with when they agreed to the school's terms. */
    public function createForSignUp(int $tenantId, int $userId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            "INSERT INTO learner_profiles (tenant_id, user_id, joined_via, consent_at, created_at, updated_at) VALUES (?, ?, 'self', ?, ?, ?)
             ON DUPLICATE KEY UPDATE consent_at = VALUES(consent_at), updated_at = VALUES(updated_at)",
            [$tenantId, $userId, $now, $now, $now],
        );
    }

    /** @param list<string> $goals */
    public function saveOnboarding(int $tenantId, int $userId, array $goals, ?string $experience, ?int $weeklyGoal, ?string $github, ?string $portfolio): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO learner_profiles (tenant_id, user_id, goals, experience, weekly_goal, github, portfolio, onboarded_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE goals = VALUES(goals), experience = VALUES(experience), weekly_goal = VALUES(weekly_goal),
                 github = VALUES(github), portfolio = VALUES(portfolio), onboarded_at = COALESCE(onboarded_at, VALUES(onboarded_at)), updated_at = VALUES(updated_at)',
            [$tenantId, $userId, implode(',', $goals), $experience, $weeklyGoal, $github, $portfolio, $now, $now, $now],
        );
    }

    /** "Not now": the home page stops asking, nothing else is recorded. */
    public function dismissOnboarding(int $tenantId, int $userId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO learner_profiles (tenant_id, user_id, onboarded_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE onboarded_at = COALESCE(onboarded_at, VALUES(onboarded_at)), updated_at = VALUES(updated_at)',
            [$tenantId, $userId, $now, $now, $now],
        );
    }

    public function saveDeveloperLinks(int $tenantId, int $userId, ?string $github, ?string $portfolio): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO learner_profiles (tenant_id, user_id, github, portfolio, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE github = VALUES(github), portfolio = VALUES(portfolio), updated_at = VALUES(updated_at)',
            [$tenantId, $userId, $github, $portfolio, $now, $now],
        );
    }
}
