-- Virtual labs (PRD 7.5) and School / Skills tracks.
--
-- A lab lesson names a lab from the built-in catalogue (lessons.lab_key).
-- Each launch is a lab_sessions row bound to the school, learner, course,
-- lesson and enrolment, with the lab version, a random seed (the numbers
-- the learner works with are derived from it, so answers are checked on
-- the server), a deadline, and the result. Learner code never runs on the
-- server: simulations and code exercises run in the learner's browser.

ALTER TABLE lessons ADD COLUMN lab_key VARCHAR(60) NULL AFTER media_url;
ALTER TABLE lessons DROP CONSTRAINT ck_lessons_type;
ALTER TABLE lessons ADD CONSTRAINT ck_lessons_type CHECK (type IN ('text', 'quiz', 'video', 'assignment', 'lab'));

-- "school" = formal curriculum subjects (e.g. SS1-SS3); "skills" = vocational and professional.
ALTER TABLE courses ADD COLUMN track VARCHAR(20) NULL;
ALTER TABLE courses ADD CONSTRAINT ck_courses_track CHECK (track IS NULL OR track IN ('school', 'skills'));

CREATE TABLE lab_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    lab_key VARCHAR(60) NOT NULL,
    lab_version INT UNSIGNED NOT NULL,
    seed CHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    score TINYINT UNSIGNED NULL,
    passed TINYINT(1) NULL,
    evidence MEDIUMTEXT NULL,
    feedback TEXT NULL,
    UNIQUE KEY uq_lab_sessions_tenant_id (tenant_id, id),
    KEY ix_lab_sessions_lesson_user (tenant_id, lesson_id, user_id),
    KEY ix_lab_sessions_user_started (user_id, started_at),
    KEY ix_lab_sessions_status_expires (status, expires_at),
    CONSTRAINT fk_lab_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_sessions_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_sessions_lesson FOREIGN KEY (tenant_id, lesson_id) REFERENCES lessons (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_sessions_enrolment FOREIGN KEY (tenant_id, enrolment_id) REFERENCES enrolments (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_lab_sessions_status CHECK (status IN ('open', 'submitted', 'expired', 'ended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
