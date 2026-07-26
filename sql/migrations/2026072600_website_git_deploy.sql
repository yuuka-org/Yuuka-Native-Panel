-- ==============================================================================
-- Migration: adds git-deploy metadata to websites - a site can now be
-- created from a git repository URL (cloned into /var/www/{domain}) and
-- later updated in-place via a "Pull/Update" button, instead of only ever
-- being an empty document root the admin uploads files into by hand.
-- git_branch is NULL for a plain (non-git) website, same as for a git
-- site left on its repo's default branch.
--
-- ADD COLUMN IF NOT EXISTS is idempotent (MariaDB 10.0.2+) - safe to
-- apply on every run, same as every other file under sql/migrations/.
-- ==============================================================================

ALTER TABLE websites
    ADD COLUMN IF NOT EXISTS git_repo_url VARCHAR(500) NULL AFTER document_root,
    ADD COLUMN IF NOT EXISTS git_branch VARCHAR(200) NULL AFTER git_repo_url;
