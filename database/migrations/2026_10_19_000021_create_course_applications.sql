-- Update 25 (ADR 0025): applying for a place on a course and waiting to be added.

-- How people get into a course: "open" (join or pay directly) or "apply" (fill a
-- form and wait for the school). With a capacity, a full course takes applications
-- for its waiting list even when it is open.
ALTER TABLE courses
    ADD COLUMN enrolment VARCHAR(10) NOT NULL DEFAULT 'open' AFTER access,
    ADD COLUMN capacity INT UNSIGNED NULL AFTER enrolment,
    ADD CONSTRAINT ck_courses_enrolment CHECK (enrolment IN ('open', 'apply'));

CREATE TABLE course_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'waiting',
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_course_applications_tenant_id (tenant_id, id),
    KEY ix_course_applications_queue (tenant_id, status, created_at),
    KEY ix_course_applications_course (tenant_id, course_id, status, created_at),
    KEY ix_course_applications_email (tenant_id, email),
    CONSTRAINT fk_course_applications_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_course_applications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_course_applications_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_course_applications_status CHECK (status IN ('waiting', 'offered', 'enrolled', 'declined', 'withdrawn'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
