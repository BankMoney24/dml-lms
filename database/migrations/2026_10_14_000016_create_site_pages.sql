-- Update 19 (ADR 0019): the school's public website pages, social profiles and search console codes.

CREATE TABLE site_pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(40) NOT NULL,
    title VARCHAR(120) NOT NULL,
    summary VARCHAR(300) NOT NULL DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_site_pages_tenant_slug (tenant_id, slug),
    CONSTRAINT fk_site_pages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT ck_site_pages_status CHECK (status IN ('draft', 'published'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Official profiles the school controls (JSON: network => https URL), and site verification codes (JSON: google, bing).
ALTER TABLE tenants
    ADD COLUMN social_links TEXT NULL,
    ADD COLUMN search_verification TEXT NULL;
