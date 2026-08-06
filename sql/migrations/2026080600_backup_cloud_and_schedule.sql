-- ==============================================================================
-- Migration: adds cloud storage upload tracking to `backups`, plus a new
-- `backup_schedules` table for recurring automatic backups (Settings >
-- Backup > Cloud Storage / Jadwal Backup) - both idempotent
-- (ADD COLUMN/CREATE TABLE IF NOT EXISTS), safe to apply on every run,
-- same as every other file under sql/migrations/.
-- ==============================================================================

ALTER TABLE backups
    ADD COLUMN IF NOT EXISTS cloud_uploaded TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS cloud_uploaded_at DATETIME NULL AFTER cloud_uploaded,
    ADD COLUMN IF NOT EXISTS cloud_path VARCHAR(255) NULL AFTER cloud_uploaded_at;

CREATE TABLE IF NOT EXISTS backup_schedules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('database','website','nodejs') NOT NULL,
    target_name     VARCHAR(190) NOT NULL,
    interval_unit   ENUM('minute','hour','day','month','year') NOT NULL,
    interval_value  INT UNSIGNED NOT NULL DEFAULT 1,
    is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at     DATETIME NULL,
    last_run_status VARCHAR(16) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_backup_schedule_target (type, target_name),
    CONSTRAINT fk_backup_schedule_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
