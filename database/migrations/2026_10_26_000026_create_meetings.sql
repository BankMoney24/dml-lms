-- Update 30 (ADR 0030): virtual meetings, quick classes and lecture mode.
--
-- meetings: a video meeting in the school's online classroom that is not a
--   class: staff meetings, parents' evenings, one-to-one calls. A host, an
--   unguessable room, a time window (it can start "now"), and whether other
--   staff in it may run it too.
-- meeting_invites: exactly who may join; resolved to people when they are
--   invited (a whole class, its parents, all staff, or named people).
-- meeting_events: join / still here / leave, like class_events, for who came
--   and for how long.
-- class_sessions.style: "lecture" (learners watch and listen, and raise a hand
--   to speak) or "discussion" (anyone may unmute). Existing classes keep the
--   old behaviour, discussion.

CREATE TABLE meetings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    host_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    agenda TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    room_name VARCHAR(80) NOT NULL,
    staff_moderate TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
    cancelled_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_meetings_tenant_id (tenant_id, id),
    UNIQUE KEY uq_meetings_room (room_name),
    KEY ix_meetings_time (tenant_id, starts_at),
    CONSTRAINT fk_meetings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_meetings_host FOREIGN KEY (host_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_meetings_status CHECK (status IN ('scheduled', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_invites (
    tenant_id BIGINT UNSIGNED NOT NULL,
    meeting_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    invited_at DATETIME NOT NULL,
    PRIMARY KEY (meeting_id, user_id),
    KEY ix_meeting_invites_user (tenant_id, user_id),
    CONSTRAINT fk_meeting_invites_meeting FOREIGN KEY (tenant_id, meeting_id) REFERENCES meetings (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_invites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    meeting_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY ix_meeting_events (tenant_id, meeting_id, user_id, created_at),
    CONSTRAINT fk_meeting_events_meeting FOREIGN KEY (tenant_id, meeting_id) REFERENCES meetings (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_meeting_events_type CHECK (type IN ('join', 'here', 'leave'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE class_sessions
    ADD COLUMN style VARCHAR(12) NOT NULL DEFAULT 'discussion' AFTER mode,
    ADD CONSTRAINT ck_class_sessions_style CHECK (style IN ('lecture', 'discussion'));
