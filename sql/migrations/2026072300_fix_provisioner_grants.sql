-- ==============================================================================
-- Migration: panel_provisioner was originally granted only CREATE, DROP,
-- ALTER, CREATE USER, RELOAD, PROCESS - which does not include data
-- privileges (SELECT, INSERT, UPDATE, DELETE, etc). MariaDB requires a
-- grantor to already POSSESS a privilege before it can pass it on to
-- someone else (WITH GRANT OPTION does not bypass this), so
-- db_grant_all()'s "GRANT ALL PRIVILEGES ON tenant_db.* TO tenant_user"
-- (used by App Installer and the Database menu) always failed with
-- "Access denied ... to database" - never triggered before because
-- nothing had exercised that full create-database-then-grant flow
-- end-to-end until now.
--
-- Re-granting is inherently idempotent (safe to apply on every run, same
-- as every other file under sql/migrations/).
-- ==============================================================================

GRANT ALL PRIVILEGES ON *.* TO 'panel_provisioner'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
