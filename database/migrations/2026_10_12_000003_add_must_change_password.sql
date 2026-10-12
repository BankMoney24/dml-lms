-- Members created by an administrator get a one-time password and must
-- choose their own at first sign-in. Additive and backward compatible.

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at;
