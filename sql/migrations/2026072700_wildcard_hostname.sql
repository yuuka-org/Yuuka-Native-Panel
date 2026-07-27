-- ==============================================================================
-- Migration: adds a wildcard/catch-all Nginx hosting flag to both websites
-- and nodejs_apps - needed for Cloudflare for SaaS "Custom Hostnames":
-- Cloudflare forwards traffic for arbitrary customer-owned domains to a
-- single fallback origin, so the origin's own Nginx vhost can't be
-- registered per-domain like every other site here; it instead has to
-- accept ANY Host header (server_name _; as the listen socket's
-- default_server).
--
-- Only one site (of either type) may hold the wildcard slot at a time -
-- nginx allows exactly one default_server per listen address:port -
-- enforced in application code (NginxService::wildcardHolder()), not by
-- this schema, since it spans two tables.
--
-- ADD COLUMN IF NOT EXISTS is idempotent (MariaDB 10.0.2+) - safe to
-- apply on every run, same as every other file under sql/migrations/.
-- ==============================================================================

ALTER TABLE websites
    ADD COLUMN IF NOT EXISTS wildcard_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER git_branch;

ALTER TABLE nodejs_apps
    ADD COLUMN IF NOT EXISTS wildcard_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER domain;
