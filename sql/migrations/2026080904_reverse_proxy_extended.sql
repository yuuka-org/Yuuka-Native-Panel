-- ==============================================================================
-- Migration: extends Reverse Proxy rules with a display Name, WebSocket
-- Support toggle, Enable Caching toggle, a custom Send Domain (Host
-- header override), and Show Proxy Path (whether the matched location
-- prefix is stripped before proxying upstream or preserved as-is).
-- Idempotent (ADD COLUMN IF NOT EXISTS), safe to apply on every run.
-- ==============================================================================

ALTER TABLE website_reverse_proxies
    ADD COLUMN IF NOT EXISTS name VARCHAR(100) NULL AFTER path_prefix,
    ADD COLUMN IF NOT EXISTS websocket_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER target_url,
    ADD COLUMN IF NOT EXISTS cache_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER websocket_enabled,
    ADD COLUMN IF NOT EXISTS send_domain VARCHAR(190) NOT NULL DEFAULT '$host' AFTER cache_enabled,
    ADD COLUMN IF NOT EXISTS show_proxy_path TINYINT(1) NOT NULL DEFAULT 1 AFTER send_domain;
