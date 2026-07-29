-- ==============================================================================
-- Migration: adds per-site Traffic Control / URL Rewrite / Default Index /
-- Redirect / Hotlink Protection settings to `websites`, plus a
-- `website_reverse_proxies` table for the (repeatable, path -> target)
-- Reverse Proxy rules - a website can have any number of these, so it
-- can't live as flat columns the way the others do.
--
-- ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS are idempotent
-- (MariaDB 10.0.2+) - safe to apply on every run, same as every other
-- file under sql/migrations/.
-- ==============================================================================

ALTER TABLE websites
    ADD COLUMN IF NOT EXISTS default_index VARCHAR(255) NULL AFTER document_root,
    ADD COLUMN IF NOT EXISTS custom_rewrite_rules TEXT NULL AFTER default_index,
    ADD COLUMN IF NOT EXISTS redirect_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_rewrite_rules,
    ADD COLUMN IF NOT EXISTS redirect_target VARCHAR(255) NULL AFTER redirect_enabled,
    ADD COLUMN IF NOT EXISTS rate_limit_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER redirect_target,
    ADD COLUMN IF NOT EXISTS rate_limit_rps INT UNSIGNED NOT NULL DEFAULT 10 AFTER rate_limit_enabled,
    ADD COLUMN IF NOT EXISTS rate_limit_burst INT UNSIGNED NOT NULL DEFAULT 20 AFTER rate_limit_rps,
    ADD COLUMN IF NOT EXISTS hotlink_protection_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER rate_limit_burst,
    ADD COLUMN IF NOT EXISTS hotlink_extensions VARCHAR(255) NOT NULL DEFAULT 'jpg|jpeg|png|gif|webp|svg|mp4|mp3|css|js' AFTER hotlink_protection_enabled,
    ADD COLUMN IF NOT EXISTS hotlink_allowed_referrers TEXT NULL AFTER hotlink_extensions;

CREATE TABLE IF NOT EXISTS website_reverse_proxies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id      INT UNSIGNED NOT NULL,
    path_prefix     VARCHAR(255) NOT NULL,
    target_url      VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reverse_proxy_path (website_id, path_prefix),
    CONSTRAINT fk_reverse_proxy_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
