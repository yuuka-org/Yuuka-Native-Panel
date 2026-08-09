-- ==============================================================================
-- Migration: Limit Access (Settings > Website > Limit Access) - two
-- independent rule types per website:
--   - website_limit_access: HTTP Basic Auth on a specific path prefix
--   - website_deny_rules: blocks specific file extensions under a path
-- Idempotent (CREATE TABLE IF NOT EXISTS), safe to apply on every run.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS website_limit_access (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    path_prefix     VARCHAR(255) NOT NULL,
    username        VARCHAR(64) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL COMMENT 'bcrypt via PASSWORD_BCRYPT, same scheme as panel_users - nginx auth_basic supports $2y$ hashes directly',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_limit_access_path (website_id, path_prefix),
    CONSTRAINT fk_limit_access_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS website_deny_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    path_prefix     VARCHAR(255) NOT NULL,
    suffixes        VARCHAR(255) NOT NULL COMMENT 'comma-separated extensions without dots, e.g. php,jsp,sh',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deny_rule_path (website_id, path_prefix),
    CONSTRAINT fk_deny_rule_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
