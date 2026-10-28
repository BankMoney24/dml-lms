-- Update 36 (ADR 0036): read-only API v1 for the school's own systems (PrimeERP and others).
--
-- api_keys: one row per key a school administrator makes. The key itself is shown
--   once and never stored: only a SHA-256 hash of it, and a short prefix so people
--   can tell keys apart. A key works only for its own school, only for reading,
--   and stops working the moment it is revoked.

CREATE TABLE api_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    prefix CHAR(8) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_api_keys_hash (key_hash),
    UNIQUE KEY uq_api_keys_tenant_id (tenant_id, id),
    KEY ix_api_keys_tenant (tenant_id, revoked_at),
    CONSTRAINT fk_api_keys_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_api_keys_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
