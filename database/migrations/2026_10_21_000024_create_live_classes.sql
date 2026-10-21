-- Update 28 (ADR 0028): live classes and attendance.
--
-- class_sessions: a lesson taught live to one class, cohort or group, online
--   (a meeting link), in a room, or both. Times are stored in UTC; the school's
--   time zone is used to enter and show them. series_id ties together the
--   sessions created by one "repeat weekly" request.
-- class_attendance: one mark per learner per session. A learner who presses
--   Join during the session is marked present (or late) by themselves; the
--   teacher's register can change any mark.

CREATE TABLE class_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    series_id CHAR(16) NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    mode VARCHAR(10) NOT NULL,
    location VARCHAR(200) NOT NULL DEFAULT '',
    join_url VARCHAR(500) NULL,
    recording_url VARCHAR(500) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'scheduled',
    cancelled_reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_class_sessions_tenant_id (tenant_id, id),
    KEY ix_class_sessions_group (tenant_id, group_id, starts_at),
    KEY ix_class_sessions_time (tenant_id, starts_at),
    CONSTRAINT fk_class_sessions_group FOREIGN KEY (tenant_id, group_id) REFERENCES learner_groups (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_class_sessions_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CONSTRAINT fk_class_sessions_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_class_sessions_mode CHECK (mode IN ('online', 'room', 'both')),
    CONSTRAINT ck_class_sessions_status CHECK (status IN ('scheduled', 'cancelled')),
    CONSTRAINT ck_class_sessions_times CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE class_attendance (
    tenant_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL,
    note VARCHAR(200) NOT NULL DEFAULT '',
    self_checked_in TINYINT(1) NOT NULL DEFAULT 0,
    marked_by BIGINT UNSIGNED NULL,
    marked_at DATETIME NOT NULL,
    PRIMARY KEY (session_id, user_id),
    KEY ix_class_attendance_user (tenant_id, user_id, status),
    CONSTRAINT fk_class_attendance_session FOREIGN KEY (tenant_id, session_id) REFERENCES class_sessions (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_class_attendance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_class_attendance_marker FOREIGN KEY (marked_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_class_attendance_status CHECK (status IN ('present', 'late', 'absent', 'excused'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
