-- School profile (set by the setup wizard) and saved wizard progress.
--
-- tenants gains what the institution onboarding wizard (PRD §6.4) asks for:
-- the kind of institution, how its year is organised, its name and look
-- (tagline, one of the built-in colour themes, an optional logo stored with
-- the uploads), public contact details, and two learning features the
-- school can switch off. setup_completed_at stays NULL until an owner or
-- admin confirms the review step.

ALTER TABLE tenants
    ADD COLUMN kind VARCHAR(30) NULL,
    ADD COLUMN tagline VARCHAR(160) NOT NULL DEFAULT '',
    ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'teal',
    ADD COLUMN logo_key VARCHAR(120) NULL,
    ADD COLUMN logo_type VARCHAR(40) NULL,
    ADD COLUMN contact_email VARCHAR(254) NOT NULL DEFAULT '',
    ADD COLUMN contact_phone VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN website VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN calendar VARCHAR(20) NULL,
    ADD COLUMN levels TEXT NULL,
    ADD COLUMN certificates_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN public_catalogue TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN setup_completed_at DATETIME NULL;

ALTER TABLE tenants
    ADD CONSTRAINT ck_tenants_kind CHECK (kind IS NULL OR kind IN ('school', 'university', 'polytechnic', 'training_centre', 'other'));

ALTER TABLE tenants
    ADD CONSTRAINT ck_tenants_calendar CHECK (calendar IS NULL OR calendar IN ('terms', 'semesters', 'flexible'));

-- One open run per wizard per school, shared by its owners and admins so
-- anyone can pick up where another stopped. The run is deleted when the
-- wizard is finished or discarded; the outcome lives in the real tables.
CREATE TABLE wizard_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    wizard VARCHAR(40) NOT NULL,
    state MEDIUMTEXT NOT NULL,
    current_step VARCHAR(40) NOT NULL,
    started_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_wizard_runs_tenant_wizard (tenant_id, wizard),
    CONSTRAINT fk_wizard_runs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_wizard_runs_started_by FOREIGN KEY (started_by) REFERENCES users (id),
    CONSTRAINT fk_wizard_runs_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
