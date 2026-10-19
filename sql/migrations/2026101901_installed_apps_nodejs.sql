-- ==============================================================================
-- Migration: App Installer bisa memasang aplikasi Node.js (pertama: 9Router)
-- selain aplikasi PHP. installed_apps.website_id jadi NULLABLE + kolom baru
-- nodejs_app_id - tepat SATU dari keduanya terisi per baris (aplikasi PHP ->
-- websites, aplikasi Node.js -> nodejs_apps). FK ON DELETE CASCADE sama
-- seperti website_id, jadi menghapus app dari menu Node.js Apps otomatis ikut
-- membersihkan catatan App Installer-nya.
--
-- Urutan statement disengaja dari yang paling aman: klien mysql berhenti di
-- statement pertama yang gagal, dan AppInstallerService::listInstalled()
-- (halaman App Installer) sudah membaca nodejs_app_id - kolom itu harus
-- sudah ada walau MODIFY di akhir gagal karena alasan apa pun (yang terdampak
-- kalau begitu hanya instal 9Router, bukan seluruh halaman).
--
-- Diterapkan di setiap install.sh / yp repair panel - semua langkah
-- idempotent. FK ditambahkan lewat cek information_schema + prepared
-- statement (bukan sintaks "FOREIGN KEY IF NOT EXISTS") supaya pasti aman
-- diulang di versi MariaDB mana pun.
-- ==============================================================================

SET NAMES utf8mb4;

ALTER TABLE installed_apps ADD COLUMN IF NOT EXISTS nodejs_app_id INT UNSIGNED NULL AFTER website_id;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'installed_apps'
      AND CONSTRAINT_NAME = 'fk_installed_apps_nodejs'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE installed_apps ADD CONSTRAINT fk_installed_apps_nodejs FOREIGN KEY (nodejs_app_id) REFERENCES nodejs_apps(id) ON DELETE CASCADE',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- MODIFY pada kolom yang dipakai FK (fk_installed_apps_website) - hanya
-- melonggarkan NOT NULL -> NULL, tipe tidak berubah. FOREIGN_KEY_CHECKS
-- dimatikan sesaat (scope sesi ini saja) supaya MariaDB tidak menolak ALTER
-- kolom ber-FK di versi yang lebih ketat.
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE installed_apps MODIFY website_id INT UNSIGNED NULL;
SET FOREIGN_KEY_CHECKS = 1;
