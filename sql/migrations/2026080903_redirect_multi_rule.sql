-- ==============================================================================
-- Migration: Redirect becomes a per-domain rule list (website_redirects)
-- instead of one sitewide toggle - a site can now redirect one alias
-- domain while still serving real content on another, matching how SSL
-- is already tracked per-domain rather than per-site. Existing sitewide
-- redirects are migrated onto the site's primary domain before the old
-- columns are dropped, so nothing already configured is lost.
-- Idempotent, safe to apply on every run.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS website_redirects (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id          INT UNSIGNED NOT NULL,
    source_domain       VARCHAR(190) NOT NULL,
    target_url          VARCHAR(255) NOT NULL,
    status_code         SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    include_uri_params  TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_redirect_source (website_id, source_domain),
    CONSTRAINT fk_redirect_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO website_redirects (website_id, source_domain, target_url, status_code, include_uri_params)
SELECT id, domain, redirect_target, 301, 1
FROM websites
WHERE redirect_enabled = 1 AND redirect_target IS NOT NULL AND redirect_target != ''
ON DUPLICATE KEY UPDATE target_url = VALUES(target_url);

ALTER TABLE websites
    DROP COLUMN IF EXISTS redirect_enabled,
    DROP COLUMN IF EXISTS redirect_target;
