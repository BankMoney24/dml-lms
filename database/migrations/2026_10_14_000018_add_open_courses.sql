-- Update 21 (ADR 0021): open courses. An open course is free and anyone can read
-- its readings and videos without an account; quizzes, labs and assignments still
-- need joining. Existing courses stay "members" (join to read) until changed.
ALTER TABLE courses
    ADD COLUMN access VARCHAR(10) NOT NULL DEFAULT 'members' AFTER currency,
    ADD CONSTRAINT ck_courses_access CHECK (access IN ('members', 'open'));
CREATE INDEX ix_courses_listing ON courses (tenant_id, status, access);
