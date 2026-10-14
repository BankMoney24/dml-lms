-- Update 20 (ADR 0020): signing up, onboarding profiles, requests to teach or follow a child, code playground.

-- Accounts made by the person themselves confirm their email; accounts staff created count as confirmed.
ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER appearance;
UPDATE users SET email_verified_at = created_at;

-- How people may join a school: closed (staff add them), code (with the school's join code) or open.
ALTER TABLE tenants
    ADD COLUMN registration VARCHAR(10) NOT NULL DEFAULT 'closed',
    ADD COLUMN join_code VARCHAR(20) NULL,
    ADD COLUMN parent_requests TINYINT(1) NOT NULL DEFAULT 1,
    ADD CONSTRAINT ck_tenants_registration CHECK (registration IN ('closed', 'code', 'open'));

-- What a learner told us when they started: goals, experience, weekly rhythm, developer links.
CREATE TABLE learner_profiles (
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    goals VARCHAR(200) NOT NULL DEFAULT '',
    experience VARCHAR(20) NULL,
    weekly_goal TINYINT UNSIGNED NULL,
    github VARCHAR(60) NULL,
    portfolio VARCHAR(255) NULL,
    joined_via VARCHAR(20) NOT NULL DEFAULT 'school',
    consent_at DATETIME NULL,
    onboarded_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, user_id),
    CONSTRAINT fk_learner_profiles_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requests the school decides on: a member asking to teach, a parent asking to follow their child.
CREATE TABLE join_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    child_name VARCHAR(120) NOT NULL DEFAULT '',
    message TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_join_requests_tenant_id (tenant_id, id),
    KEY ix_join_requests_queue (tenant_id, status, created_at),
    CONSTRAINT fk_join_requests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_join_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_join_requests_kind CHECK (kind IN ('teach', 'guardian')),
    CONSTRAINT ck_join_requests_status CHECK (status IN ('pending', 'approved', 'declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A member's saved code in the playground. Private to them.
CREATE TABLE code_snippets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    code MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_code_snippets_tenant_id (tenant_id, id),
    KEY ix_code_snippets_user (tenant_id, user_id, updated_at),
    CONSTRAINT fk_code_snippets_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email confirmation links for people who sign themselves up.
ALTER TABLE account_tokens DROP CONSTRAINT ck_account_tokens_purpose;
ALTER TABLE account_tokens ADD CONSTRAINT ck_account_tokens_purpose CHECK (purpose IN ('reset', 'invite', 'verify'));
