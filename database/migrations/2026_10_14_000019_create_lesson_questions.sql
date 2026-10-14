-- Update 22 (ADR 0022): questions and answers under each lesson, for the people
-- taking the course and its teachers. Never public, even in open courses.
CREATE TABLE lesson_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'open',
    accepted_answer_id BIGINT UNSIGNED NULL,
    answer_count INT UNSIGNED NOT NULL DEFAULT 0,
    staff_answered TINYINT(1) NOT NULL DEFAULT 0,
    last_activity_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_lesson_questions_tenant_id (tenant_id, id),
    KEY ix_lesson_questions_lesson (tenant_id, lesson_id, status, last_activity_at),
    KEY ix_lesson_questions_waiting (tenant_id, status, staff_answered, last_activity_at),
    CONSTRAINT fk_lesson_questions_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_questions_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_questions_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_lesson_questions_status CHECK (status IN ('open', 'hidden', 'deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lesson_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    by_staff TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL DEFAULT 'visible',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_lesson_answers_tenant_id (tenant_id, id),
    KEY ix_lesson_answers_question (tenant_id, question_id, status, id),
    CONSTRAINT fk_lesson_answers_question FOREIGN KEY (tenant_id, question_id) REFERENCES lesson_questions (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_answers_membership FOREIGN KEY (tenant_id, user_id) REFERENCES memberships (tenant_id, user_id) ON DELETE CASCADE,
    CONSTRAINT ck_lesson_answers_status CHECK (status IN ('visible', 'hidden', 'deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
