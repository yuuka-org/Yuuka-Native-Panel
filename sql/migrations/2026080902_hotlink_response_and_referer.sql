-- ==============================================================================
-- Migration: Hotlink Protection gets a configurable Response code and an
-- "Allow Empty HTTP_REFERER" toggle (previously always allowed, hardcoded
-- via nginx's `valid_referers none ...`). hotlink_extensions switches
-- from pipe-separated to comma-separated, matching the tag-chip UI now
-- shared with Deny Access (Limit Access feature) - existing pipe-joined
-- values are converted in place so nothing already saved breaks.
-- Idempotent, safe to apply on every run.
-- ==============================================================================

ALTER TABLE websites
    ADD COLUMN IF NOT EXISTS hotlink_response_code SMALLINT UNSIGNED NOT NULL DEFAULT 403 AFTER hotlink_allowed_referrers,
    ADD COLUMN IF NOT EXISTS hotlink_allow_empty_referer TINYINT(1) NOT NULL DEFAULT 1 AFTER hotlink_response_code;

UPDATE websites
    SET hotlink_extensions = REPLACE(hotlink_extensions, '|', ',')
    WHERE hotlink_extensions LIKE '%|%';

ALTER TABLE websites
    ALTER COLUMN hotlink_extensions SET DEFAULT 'jpg,jpeg,png,gif,webp,svg,mp4,mp3,css,js';
