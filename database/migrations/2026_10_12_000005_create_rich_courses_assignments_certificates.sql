-- Richer courses: course details, video and assignment lessons, quiz
-- explanations, assignment submissions with educator review, and
-- certificates. Additive: the previous release ignores the new columns.

ALTER TABLE courses
    ADD COLUMN level VARCHAR(20) NULL AFTER summary,
    ADD COLUMN duration_hours SMALLINT UNSIGNED NULL AFTER level,
    ADD COLUMN outcomes TEXT NULL AFTER duration_hours,
    ADD COLUMN prerequisites TEXT NULL AFTER outcomes,
    ADD CONSTRAINT ck_courses_level CHECK (level IS NULL OR level IN ('beginner', 'intermediate', 'advanced'));

ALTER TABLE lessons ADD COLUMN media_url VARCHAR(500) NULL AFTER body;
-- DROP CONSTRAINT works on MySQL 8.0.19+ and MariaDB 10.2+.
ALTER TABLE lessons DROP CONSTRAINT ck_lessons_type;
ALTER TABLE lessons ADD CONSTRAINT ck_lessons_type CHECK (type IN ('text', 'quiz', 'video', 'assignment'));

ALTER TABLE questions
    ADD COLUMN explanation TEXT NULL AFTER prompt;

-- Every submission is kept; the newest one per learner and lesson is current.
CREATE TABLE assignment_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body MEDIUMTEXT NOT NULL,
    link_url VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'submitted',
    feedback TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    submitted_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_submissions_tenant_id (tenant_id, id),
    KEY ix_submissions_queue (tenant_id, status, submitted_at),
    KEY ix_submissions_learner (tenant_id, enrolment_id, lesson_id, id),
    CONSTRAINT fk_submissions_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_enrolment FOREIGN KEY (tenant_id, enrolment_id) REFERENCES enrolments (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id),
    CONSTRAINT ck_submissions_status CHECK (status IN ('submitted', 'approved', 'changes_requested'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name and title are copied at issue time so a certificate keeps saying what
-- it said, even if the course or the person is renamed later.
CREATE TABLE certificates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    code CHAR(12) NOT NULL,
    learner_name VARCHAR(190) NOT NULL,
    course_title VARCHAR(190) NOT NULL,
    issued_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(500) NULL,
    UNIQUE KEY uq_certificates_code (code),
    UNIQUE KEY uq_certificates_enrolment (tenant_id, enrolment_id),
    KEY ix_certificates_user (tenant_id, user_id),
    CONSTRAINT fk_certificates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_certificates_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
