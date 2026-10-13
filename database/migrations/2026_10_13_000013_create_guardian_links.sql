-- Parents and guardians (PRD 7.7, PAR-001..004).
--
-- A school administrator links a guardian to a learner after checking who
-- they are; the link records who verified it and when, and what the guardian
-- may see: progress, results, and the class's notices. (Someone cannot be
-- their own guardian; MySQL forbids that CHECK next to the cascading keys,
-- so GuardianService enforces it.) Guardians see only
-- linked learners, only what each link allows, and change nothing.

CREATE TABLE guardian_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    guardian_id BIGINT UNSIGNED NOT NULL,
    learner_id BIGINT UNSIGNED NOT NULL,
    relationship VARCHAR(20) NOT NULL,
    can_progress TINYINT(1) NOT NULL DEFAULT 1,
    can_results TINYINT(1) NOT NULL DEFAULT 1,
    can_notices TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME NOT NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_guardian_links (tenant_id, guardian_id, learner_id),
    KEY ix_guardian_links_learner (tenant_id, learner_id),
    CONSTRAINT fk_guardian_links_guardian FOREIGN KEY (tenant_id, guardian_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT fk_guardian_links_learner FOREIGN KEY (tenant_id, learner_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_guardian_links_status CHECK (status IN ('active', 'revoked')),
    CONSTRAINT ck_guardian_links_relationship CHECK (relationship IN ('mother', 'father', 'guardian', 'grandparent', 'sponsor', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
