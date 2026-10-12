-- Course catalogue, delivery, enrolment, progress and quizzes.
-- Child tables repeat tenant_id and use composite foreign keys
-- (tenant_id, parent_id) so the database itself refuses a row that points
-- at another tenant's parent record.

CREATE TABLE courses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(190) NOT NULL,
    summary TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_courses_tenant_slug (tenant_id, slug),
    UNIQUE KEY uq_courses_tenant_id (tenant_id, id),
    KEY ix_courses_tenant_status (tenant_id, status),
    CONSTRAINT fk_courses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_courses_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_courses_status CHECK (status IN ('draft', 'published', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    position INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_modules_tenant_id (tenant_id, id),
    KEY ix_modules_course (tenant_id, course_id, position),
    CONSTRAINT fk_modules_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lessons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    position INT UNSIGNED NOT NULL,
    is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
    is_preview TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_lessons_tenant_id (tenant_id, id),
    KEY ix_lessons_module (tenant_id, module_id, position),
    KEY ix_lessons_course (tenant_id, course_id),
    CONSTRAINT fk_lessons_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lessons_module FOREIGN KEY (tenant_id, module_id) REFERENCES course_modules (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_lessons_type CHECK (type IN ('text', 'quiz'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    pass_threshold TINYINT UNSIGNED NOT NULL DEFAULT 70,
    max_attempts TINYINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_assessments_lesson (tenant_id, lesson_id),
    UNIQUE KEY uq_assessments_tenant_id (tenant_id, id),
    CONSTRAINT fk_assessments_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_assessments_threshold CHECK (pass_threshold BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    prompt TEXT NOT NULL,
    position INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_questions_tenant_id (tenant_id, id),
    KEY ix_questions_assessment (tenant_id, assessment_id, position),
    CONSTRAINT fk_questions_assessment FOREIGN KEY (tenant_id, assessment_id) REFERENCES assessments (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE question_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(500) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    position INT UNSIGNED NOT NULL,
    KEY ix_options_question (tenant_id, question_id, position),
    CONSTRAINT fk_options_question FOREIGN KEY (tenant_id, question_id) REFERENCES questions (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrolments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    enrolled_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_enrolments_course_user (tenant_id, course_id, user_id),
    UNIQUE KEY uq_enrolments_tenant_id (tenant_id, id),
    KEY ix_enrolments_user (tenant_id, user_id),
    CONSTRAINT fk_enrolments_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_enrolments_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_enrolments_status CHECK (status IN ('active', 'completed', 'withdrawn'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lesson_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_progress_enrolment_lesson (tenant_id, enrolment_id, lesson_id),
    CONSTRAINT fk_progress_enrolment FOREIGN KEY (tenant_id, enrolment_id) REFERENCES enrolments (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_progress_status CHECK (status IN ('started', 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    assessment_id BIGINT UNSIGNED NOT NULL,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key CHAR(32) NOT NULL,
    score_percent TINYINT UNSIGNED NOT NULL,
    correct_count INT UNSIGNED NOT NULL,
    question_count INT UNSIGNED NOT NULL,
    passed TINYINT(1) NOT NULL,
    answers JSON NOT NULL,
    submitted_at DATETIME NOT NULL,
    UNIQUE KEY uq_attempts_idempotency (tenant_id, user_id, idempotency_key),
    KEY ix_attempts_assessment_user (tenant_id, assessment_id, user_id),
    CONSTRAINT fk_attempts_assessment FOREIGN KEY (tenant_id, assessment_id) REFERENCES assessments (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_enrolment FOREIGN KEY (tenant_id, enrolment_id) REFERENCES enrolments (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
