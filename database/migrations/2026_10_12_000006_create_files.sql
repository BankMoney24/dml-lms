-- Uploaded files: lesson attachments and assignment submission files.
-- The bytes live under STORAGE_PATH/uploads (outside the web root) and are
-- served only through the application after an access check. Each row
-- belongs to exactly one lesson or one submission (enforced by FileService;
-- MySQL does not allow CHECK constraints on columns with cascading FKs).

CREATE TABLE files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NULL,
    submission_id BIGINT UNSIGNED NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    storage_key VARCHAR(120) NOT NULL,
    scan_status VARCHAR(20) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_files_storage_key (storage_key),
    UNIQUE KEY uq_files_tenant_id (tenant_id, id),
    KEY ix_files_lesson (tenant_id, lesson_id, position),
    KEY ix_files_submission (tenant_id, submission_id),
    CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_files_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_files_submission FOREIGN KEY (tenant_id, submission_id) REFERENCES assignment_submissions (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_files_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id),
    CONSTRAINT ck_files_scan CHECK (scan_status IN ('clean', 'unscanned'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
