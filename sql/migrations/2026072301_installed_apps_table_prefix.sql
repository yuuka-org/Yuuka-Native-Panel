-- ==============================================================================
-- Migration: installed_apps.table_prefix - WP Manager needs to read/write
-- {prefix}options (active_plugins, template, stylesheet) in a WordPress
-- site's own tenant database, but the table prefix generated at install
-- time (WordpressInstallerService::buildConfig()) was previously only ever
-- baked into wp-config.php text, never persisted anywhere queryable.
--
-- Applied on every install.sh/yp repair panel run like every file under
-- sql/migrations/ - ADD COLUMN IF NOT EXISTS makes it naturally idempotent
-- (MariaDB 10.6+, confirmed on the target server).
-- ==============================================================================

SET NAMES utf8mb4;

ALTER TABLE installed_apps ADD COLUMN IF NOT EXISTS table_prefix VARCHAR(20) NULL AFTER app_version;
