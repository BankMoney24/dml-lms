-- Classes, cohorts or groups of learners (PRD 6.4 academic structure, ADM-001 class reports).
--
-- The school chooses the word (tenants.group_term): a secondary school says
-- "class", a training centre "cohort". A group has learners and teachers
-- (both must be members of the school) and a set of courses. Learners in a
-- group are enrolled in its courses; teachers see the group's progress.
-- Learners see their own group and its courses, never its other members.

ALTER TABLE tenants ADD COLUMN group_term VARCHAR(20) NULL AFTER levels;
ALTER TABLE tenants ADD CONSTRAINT ck_tenants_group_term CHECK (group_term IS NULL OR group_term IN ('class', 'cohort', 'group', 'stream', 'set'));

CREATE TABLE learner_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    level VARCHAR(60) NULL,
    period_label VARCHAR(60) NULL,
    archived_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_learner_groups_tenant_id (tenant_id, id),
    UNIQUE KEY uq_learner_groups_name (tenant_id, name),
    CONSTRAINT fk_learner_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_learner_groups_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learner_group_members (
    tenant_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    added_by BIGINT UNSIGNED NULL,
    added_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, group_id, user_id),
    KEY ix_group_members_user (tenant_id, user_id),
    CONSTRAINT fk_group_members_group FOREIGN KEY (tenant_id, group_id) REFERENCES learner_groups (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_group_members_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_group_members_role CHECK (role IN ('learner', 'teacher'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learner_group_courses (
    tenant_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    due_on DATE NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, group_id, course_id),
    KEY ix_group_courses_course (tenant_id, course_id),
    CONSTRAINT fk_group_courses_group FOREIGN KEY (tenant_id, group_id) REFERENCES learner_groups (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_group_courses_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
