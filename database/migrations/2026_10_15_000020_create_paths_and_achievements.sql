-- Update 23 (ADR 0023): learning paths (courses in order) and achievements.

CREATE TABLE learning_paths (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(190) NOT NULL,
    summary TEXT NOT NULL,
    level VARCHAR(20) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_learning_paths_slug (tenant_id, slug),
    UNIQUE KEY uq_learning_paths_tenant_id (tenant_id, id),
    CONSTRAINT fk_learning_paths_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_learning_paths_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_learning_paths_status CHECK (status IN ('draft', 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_path_courses (
    tenant_id BIGINT UNSIGNED NOT NULL,
    path_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, path_id, course_id),
    KEY ix_learning_path_courses_course (tenant_id, course_id),
    CONSTRAINT fk_learning_path_courses_path FOREIGN KEY (tenant_id, path_id) REFERENCES learning_paths (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_learning_path_courses_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who chose to follow a path ("Start this path").
CREATE TABLE learning_path_followers (
    tenant_id BIGINT UNSIGNED NOT NULL,
    path_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, path_id, user_id),
    KEY ix_learning_path_followers_user (tenant_id, user_id),
    CONSTRAINT fk_learning_path_followers_path FOREIGN KEY (tenant_id, path_id) REFERENCES learning_paths (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_learning_path_followers_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Achievements a learner has earned. earned_at is when the thing happened, not when it was noticed.
CREATE TABLE achievements (
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    earned_at DATETIME NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, user_id, code),
    CONSTRAINT fk_achievements_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
