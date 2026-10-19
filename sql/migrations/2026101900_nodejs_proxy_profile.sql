-- ==============================================================================
-- Migration: nodejs_apps.proxy_profile - 'default' (snippets/proxy-params.conf
-- biasa, perilaku lama tidak berubah) atau 'streaming' (proxy_buffering off,
-- timeout 15 menit, body 100 MB - dibutuhkan AI gateway seperti 9Router dari
-- App Installer: respons di-stream lama & request berkonteks besar). Dibaca
-- setiap kali vhost Node.js digenerate ulang (createApp/addDomain/
-- applySslForDomain), jadi profilnya tidak hilang saat SSL diaktifkan
-- belakangan - lihat nginx_build_nodejs_proxy_config().
--
-- Sengaja file TERPISAH dari migrasi installed_apps (2026101901): klien mysql
-- berhenti di statement pertama yang gagal, sementara NodeService::createApp()
-- menyebut kolom ini untuk SEMUA app Node.js - kegagalan ALTER yang lebih
-- rumit di file lain tidak boleh ikut mematikan pembuatan app Node.js biasa.
--
-- Diterapkan di setiap install.sh / yp repair panel - ADD COLUMN IF NOT
-- EXISTS membuatnya idempotent.
-- ==============================================================================

SET NAMES utf8mb4;

ALTER TABLE nodejs_apps ADD COLUMN IF NOT EXISTS proxy_profile VARCHAR(16) NOT NULL DEFAULT 'default' AFTER node_env;
