-- Update 37 (ADR 0037): paying for a course in parts.
--
-- courses.instalments: how many monthly parts a learner may split the price into
--   (2, 3 or 4), or NULL when the course is paid in full only. Set by a school
--   administrator next to the price.
--
-- payment_plans: one row per learner who chose to pay a course in parts. The
--   price and the number of parts are copied when the plan starts, so a later
--   change of price never changes what the learner agreed to. Part 1 opens the
--   course; part N is due (N - 1) x 30 days after part 1 was paid. When a part is
--   more than 7 days late the course pauses until it is paid. status:
--     pending      part 1 not paid yet
--     active       part 1 paid, more to pay
--     completed    every part paid
--     written_off  the school stopped asking for the rest (the course stays open)
--   reminded holds the last reminder sent ("2:soon", "2:due", "2:paused") so the
--   daily reminder job sends each one once.
--
-- payments.plan_id / plan_part and payment_claims.plan_id / plan_part link a
-- payment, or a learner's "I have paid" note, to the part it pays.

ALTER TABLE courses ADD COLUMN instalments TINYINT UNSIGNED NULL AFTER currency;

CREATE TABLE payment_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    parts TINYINT UNSIGNED NOT NULL,
    total_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    started_at DATETIME NULL,
    reminded VARCHAR(16) NULL,
    ended_by BIGINT UNSIGNED NULL,
    ended_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_plans_tenant_id (tenant_id, id),
    KEY ix_payment_plans_learner (tenant_id, user_id, course_id, status),
    KEY ix_payment_plans_status (status, started_at),
    CONSTRAINT fk_payment_plans_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_plans_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_payment_plans_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CONSTRAINT fk_payment_plans_ended_by FOREIGN KEY (ended_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments
    ADD COLUMN plan_id BIGINT UNSIGNED NULL AFTER list_price_minor,
    ADD COLUMN plan_part TINYINT UNSIGNED NULL AFTER plan_id,
    ADD KEY ix_payments_plan (tenant_id, plan_id, status),
    ADD CONSTRAINT fk_payments_plan FOREIGN KEY (tenant_id, plan_id) REFERENCES payment_plans (tenant_id, id);

ALTER TABLE payment_claims
    ADD COLUMN plan_id BIGINT UNSIGNED NULL,
    ADD COLUMN plan_part TINYINT UNSIGNED NULL,
    ADD CONSTRAINT fk_payment_claims_plan FOREIGN KEY (tenant_id, plan_id) REFERENCES payment_plans (tenant_id, id);
