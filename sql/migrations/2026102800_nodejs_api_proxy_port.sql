-- ==============================================================================
-- Migration: nodejs_apps.api_proxy_port - port lokal kedua milik app yang
-- diproxy Nginx di /v1/ pada setiap domain app itu (NULL = tidak ada).
-- Dipakai App Installer > Hermes > API: API server OpenAI-compatible Hermes
-- berjalan di port terpisah dari dashboard-nya, jadi Cline/Open WebUI dkk
-- bisa memakai https://<domain-hermes>/v1 lewat jalur yang sama dengan
-- dashboard (Nginx, termasuk di belakang Cloudflare Tunnel). Dibaca setiap
-- kali vhost Node.js digenerate ulang (addDomain/applySslForDomain/
-- NodeService::setApiProxyPort) - lihat nginx_build_nodejs_proxy_config().
--
-- File terpisah dengan alasan yang sama seperti 2026101900: ALTER yang gagal
-- di file lain tidak boleh ikut menghentikan migrasi ini, begitu pula
-- sebaliknya.
--
-- Diterapkan di setiap install.sh / yp repair panel - ADD COLUMN IF NOT
-- EXISTS membuatnya idempotent.
-- ==============================================================================

SET NAMES utf8mb4;

ALTER TABLE nodejs_apps ADD COLUMN IF NOT EXISTS api_proxy_port INT UNSIGNED NULL DEFAULT NULL AFTER proxy_profile;
