-- In-app notification centre (NTF-001) and class announcements.
--
-- A notification is a short line for one member of one school with an
-- optional link inside the site. Titles stay general ("Changes requested on
-- Lab 2"), never marks or private details (PAR-004). Announcements are
-- posted by a group's teachers to its learners.

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(40) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body VARCHAR(500) NULL,
    link VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    KEY ix_notifications_user (tenant_id, user_id, read_at, id),
    CONSTRAINT fk_notifications_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY ix_announcements_group (tenant_id, group_id, id),
    CONSTRAINT fk_announcements_group FOREIGN KEY (tenant_id, group_id) REFERENCES learner_groups (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_announcements_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
