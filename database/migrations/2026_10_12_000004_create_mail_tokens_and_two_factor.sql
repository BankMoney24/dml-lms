-- Email outbox, single-use account tokens (reset / invitation), generic rate
-- limits, and two-step sign-in. All additive.

CREATE TABLE mail_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    to_email VARCHAR(254) NOT NULL,
    to_name VARCHAR(120) NULL,
    subject VARCHAR(255) NOT NULL,
    -- Bodies can contain single-use links; they are erased once delivered or abandoned.
    text_body MEDIUMTEXT NULL,
    html_body MEDIUMTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    KEY ix_outbox_due (status, next_attempt_at),
    KEY ix_outbox_tenant (tenant_id, created_at),
    CONSTRAINT ck_outbox_status CHECK (status IN ('queued', 'sending', 'sent', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_account_tokens_hash (token_hash),
    KEY ix_account_tokens_user (user_id, purpose),
    CONSTRAINT fk_account_tokens_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_account_tokens_purpose CHECK (purpose IN ('reset', 'invite'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket CHAR(64) NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(255) NULL AFTER must_change_password,
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at;

CREATE TABLE recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_recovery_codes_hash (code_hash),
    KEY ix_recovery_codes_user (user_id),
    CONSTRAINT fk_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
