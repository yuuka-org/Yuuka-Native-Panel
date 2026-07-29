-- ==============================================================================
-- Migration: lets MORE THAN ONE site (across websites + nodejs_apps
-- combined) hold a wildcard/Cloudflare-for-SaaS slot at the same time.
--
-- Previously exactly one site total could ever have wildcard_enabled=1,
-- because every wildcard vhost shared the same `listen 80 default_server`
-- socket - nginx allows only one default_server per listen address:port.
-- Each additional slot now gets its OWN dedicated local port
-- (wildcard_port) with its own `listen <port> default_server` - nginx has
-- no problem with many DIFFERENT ports each having their own
-- default_server. Reaching a given slot from a SEPARATE Cloudflare SaaS
-- zone still requires its own independent Cloudflare Tunnel instance
-- pointed at that port (cloudflared's Catch-All Rule always resolves to
-- exactly one destination per tunnel - see wiki/Fitur-Panel.md and
-- wiki/Troubleshooting.md for the manual steps, this is NOT automated by
-- the panel).
--
-- ADD COLUMN IF NOT EXISTS is idempotent (MariaDB 10.0.2+) - safe to
-- apply on every run, same as every other file under sql/migrations/.
-- ==============================================================================

ALTER TABLE websites
    ADD COLUMN IF NOT EXISTS wildcard_port INT UNSIGNED NULL AFTER wildcard_enabled;

ALTER TABLE nodejs_apps
    ADD COLUMN IF NOT EXISTS wildcard_port INT UNSIGNED NULL AFTER wildcard_enabled;

-- Whichever site already had wildcard_enabled=1 BEFORE this migration was
-- always on port 80 (the only option that ever existed) - backfill that
-- explicitly rather than leaving it NULL, so an already-working
-- production Tunnel (Catch-All Rule already pointed at
-- http://127.0.0.1:80, per wiki/Troubleshooting.md) keeps working exactly
-- as-is after upgrading, instead of silently moving to a different port
-- on the next config regenerate.
UPDATE websites SET wildcard_port = 80 WHERE wildcard_enabled = 1 AND wildcard_port IS NULL;
UPDATE nodejs_apps SET wildcard_port = 80 WHERE wildcard_enabled = 1 AND wildcard_port IS NULL;
