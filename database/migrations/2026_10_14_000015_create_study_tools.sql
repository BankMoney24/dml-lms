-- Update 18 (ADR 0018): appearance, saved lessons with private notes, calendar links.

-- Each person's choice of light, dark or the device's setting.
ALTER TABLE users ADD COLUMN appearance VARCHAR(10) NOT NULL DEFAULT 'system' AFTER must_change_password;
ALTER TABLE users ADD CONSTRAINT ck_users_appearance CHECK (appearance IN ('system', 'light', 'dark'));

-- A learner's own bookmark and note on a lesson. Private: never shown to teachers or guardians.
CREATE TABLE lesson_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    is_saved TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_lesson_notes_user_lesson (tenant_id, user_id, lesson_id),
    KEY ix_lesson_notes_user_updated (tenant_id, user_id, updated_at),
    CONSTRAINT fk_lesson_notes_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_notes_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A private calendar link per person per school. Only a SHA-256 of the secret is kept.
ALTER TABLE memberships
    ADD COLUMN calendar_token_hash CHAR(64) NULL AFTER status,
    ADD UNIQUE KEY uq_memberships_calendar_token (calendar_token_hash);
