-- ==============================================================================
-- Migration: plugin system - installed plugins are tracked here (metadata
-- only; the actual plugin code lives on disk under
-- /opt/server-panel/plugins/<slug>/, never in this database and never in
-- this repo - see .gitignore). Trust model (explicitly chosen by the
-- operator over a sandboxed alternative): an installed+enabled plugin's
-- own scripts run with FULL ROOT privilege via panel-exec.sh's
-- plugin-exec dispatch - installing a plugin is equivalent to granting it
-- root on this server. Plugin management is admin-only (see Rbac).
-- ==============================================================================

CREATE TABLE IF NOT EXISTS plugins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(64) NOT NULL UNIQUE,
    name            VARCHAR(190) NOT NULL,
    version         VARCHAR(32) NOT NULL DEFAULT '0.0.0',
    is_enabled      TINYINT(1) NOT NULL DEFAULT 0,
    installed_by    INT UNSIGNED NULL,
    installed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_plugin_user FOREIGN KEY (installed_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
