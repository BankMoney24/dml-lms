-- Update 32 (ADR 0032): discount codes, refunds and per-course completion rules.
--
-- courses.completion_min_average: besides every required lesson, a course can
--   ask for an average of at least this many percent across its required
--   quizzes (each quiz's best score). NULL means no average is needed.
-- coupons: a school's discount codes. Either a percentage or a fixed amount off
--   (in the course currency), for one course or every paid course, with an
--   optional last day and number of uses. A code is used when a payment with it
--   is confirmed; a 100% code enrols without going to Paystack.
-- payments.coupon_id / list_price_minor: the code used and the price before it,
--   so receipts and reports show the discount. provider 'coupon' is a free
--   place from a 100% code; status 'refunded' is a payment paid back in full.
-- payment_refunds: money paid back, in full or in part. Paystack refunds start
--   as pending and are settled by Paystack's refund webhooks; refunds of
--   payments recorded by hand are recorded as processed straight away.

ALTER TABLE courses
    ADD COLUMN completion_min_average TINYINT UNSIGNED NULL AFTER capacity;

CREATE TABLE coupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    percent_off TINYINT UNSIGNED NULL,
    amount_off_minor INT UNSIGNED NULL,
    currency CHAR(3) NULL,
    max_uses INT UNSIGNED NULL,
    expires_on DATE NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    note VARCHAR(160) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_coupons_tenant_code (tenant_id, code),
    UNIQUE KEY uq_coupons_tenant_id (tenant_id, id),
    CONSTRAINT fk_coupons_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_coupons_course FOREIGN KEY (tenant_id, course_id) REFERENCES courses (tenant_id, id),
    CONSTRAINT fk_coupons_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_coupons_status CHECK (status IN ('active', 'disabled')),
    CONSTRAINT ck_coupons_kind CHECK ((percent_off IS NULL) <> (amount_off_minor IS NULL)),
    CONSTRAINT ck_coupons_percent CHECK (percent_off IS NULL OR percent_off BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments DROP CONSTRAINT ck_payments_status;
ALTER TABLE payments DROP CONSTRAINT ck_payments_provider;
ALTER TABLE payments
    ADD COLUMN coupon_id BIGINT UNSIGNED NULL AFTER note,
    ADD COLUMN list_price_minor INT UNSIGNED NULL AFTER coupon_id,
    ADD KEY ix_payments_coupon (tenant_id, coupon_id, status),
    ADD CONSTRAINT fk_payments_coupon FOREIGN KEY (tenant_id, coupon_id) REFERENCES coupons (tenant_id, id),
    ADD CONSTRAINT ck_payments_status CHECK (status IN ('pending', 'paid', 'failed', 'refunded')),
    ADD CONSTRAINT ck_payments_provider CHECK (provider IN ('paystack', 'manual', 'coupon'));

CREATE TABLE payment_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount_minor INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(12) NOT NULL,
    provider_refund_id VARCHAR(40) NULL,
    failure VARCHAR(255) NULL,
    withdrew_learner TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    KEY ix_payment_refunds_payment (tenant_id, payment_id),
    KEY ix_payment_refunds_provider (provider_refund_id),
    CONSTRAINT fk_payment_refunds_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_refunds_payment FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id),
    CONSTRAINT fk_payment_refunds_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_payment_refunds_status CHECK (status IN ('pending', 'processed', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
