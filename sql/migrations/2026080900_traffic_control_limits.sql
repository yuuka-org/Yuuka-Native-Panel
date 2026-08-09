-- ==============================================================================
-- Migration: adds Max Connections (total + per-IP) and Max Bandwidth per
-- Request to Traffic Control (Settings > Website > Traffic Control),
-- alongside the existing rate_limit_* (requests/sec) columns. 0 means
-- unlimited/off for all three - no separate "enabled" flag needed.
-- Idempotent (ADD COLUMN IF NOT EXISTS), safe to apply on every run.
-- ==============================================================================

ALTER TABLE websites
    ADD COLUMN IF NOT EXISTS max_conn_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER rate_limit_burst,
    ADD COLUMN IF NOT EXISTS max_conn_per_ip INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_conn_total,
    ADD COLUMN IF NOT EXISTS max_bandwidth_kbps INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_conn_per_ip;
