-- Help requests (SUP-001): anyone signed in asks the school for help, with
-- safe attachments; the school's support staff answer in the same thread and
-- track each request as open, answered (waiting for the person) or resolved.

CREATE TABLE support_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(20) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    UNIQUE KEY uq_support_requests_tenant_id (tenant_id, id),
    KEY ix_support_requests_status (tenant_id, status, updated_at),
    KEY ix_support_requests_user (tenant_id, user_id, updated_at),
    CONSTRAINT fk_support_requests_member FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_support_requests_status CHECK (status IN ('open', 'answered', 'resolved')),
    CONSTRAINT ck_support_requests_category CHECK (category IN ('account', 'course', 'payment', 'technical', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    from_staff TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_support_messages_tenant_id (tenant_id, id),
    KEY ix_support_messages_request (tenant_id, request_id, id),
    CONSTRAINT fk_support_messages_request FOREIGN KEY (tenant_id, request_id) REFERENCES support_requests (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_support_messages_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE files ADD COLUMN support_message_id BIGINT UNSIGNED NULL AFTER submission_id;
ALTER TABLE files ADD KEY ix_files_support_message (tenant_id, support_message_id);
ALTER TABLE files ADD CONSTRAINT fk_files_support_message FOREIGN KEY (tenant_id, support_message_id) REFERENCES support_messages (tenant_id, id) ON DELETE CASCADE;
