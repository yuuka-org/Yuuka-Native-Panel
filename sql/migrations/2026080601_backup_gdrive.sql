-- ==============================================================================
-- Migration: adds Google Drive as a second, independent cloud backup
-- target alongside the S3-compatible one from 2026080600 - separate
-- tracking columns since a backup can be mirrored to both at once.
-- Idempotent (ADD COLUMN IF NOT EXISTS), safe to apply on every run.
-- ==============================================================================

ALTER TABLE backups
    ADD COLUMN IF NOT EXISTS cloud_uploaded_gdrive TINYINT(1) NOT NULL DEFAULT 0 AFTER cloud_path,
    ADD COLUMN IF NOT EXISTS cloud_uploaded_gdrive_at DATETIME NULL AFTER cloud_uploaded_gdrive,
    ADD COLUMN IF NOT EXISTS cloud_path_gdrive VARCHAR(255) NULL AFTER cloud_uploaded_gdrive_at;
