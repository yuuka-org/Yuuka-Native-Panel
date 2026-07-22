#!/usr/bin/env bash
# ==============================================================================
# mariadb.sh - Install & secure MariaDB, create panel + provisioner DB users
# ==============================================================================

module_mariadb_install() {
    log_step "Install MariaDB Server"

    if command_exists mariadb || command_exists mysql; then
        log_ok "MariaDB/MySQL sudah terinstall, lewati instalasi paket"
    else
        apt_install mariadb-server mariadb-client
    fi

    service_enable_now mariadb
    state_mark "mariadb:installed"
}

module_mariadb_secure() {
    log_step "Mengamankan instalasi MariaDB (setara mysql_secure_installation)"

    if state_has "mariadb:secured"; then
        log_ok "MariaDB sudah pernah diamankan sebelumnya, lewati"
        return 0
    fi

    # Determine current root auth method: fresh installs use unix_socket for root@localhost
    local ROOT_AUTH_SQL
    ROOT_AUTH_SQL=$(mktemp)
    cat > "$ROOT_AUTH_SQL" <<'SQL'
-- Remove anonymous users
DELETE FROM mysql.global_priv WHERE User='';
-- Disallow remote root login
DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
SQL

    if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
        spinner_run "Menerapkan hardening dasar MariaDB" -- mysql -u root -e "$(cat "$ROOT_AUTH_SQL")"
    else
        log_warn "Tidak dapat login sebagai root via unix_socket, lewati hardening otomatis. Jalankan mysql_secure_installation manual jika perlu."
    fi
    rm -f "$ROOT_AUTH_SQL"

    state_mark "mariadb:secured"
}

# ---------------------------------------------------------------------------
# Create:
#  - a dedicated DB admin/provisioner account used ONLY by the panel backend
#    to create/drop tenant databases & users (least privilege beyond root)
#  - a human admin account (optional) matching MYSQL_ADMIN_USER for CLI use
# ---------------------------------------------------------------------------
module_mariadb_create_accounts() {
    log_step "Membuat akun database untuk Panel & Administrator"

    if state_has "mariadb:accounts_created"; then
        log_ok "Akun database panel sudah pernah dibuat sebelumnya, lewati (password tidak diubah)"
        # Downstream steps (panel.sh) only need these when .env doesn't exist
        # yet; on a re-run .env is already present and never overwritten, so
        # leaving these unset here is safe.
        PANEL_APP_DB_NAME="server_panel"
        PANEL_APP_DB_USER="panel_app"
        export PANEL_APP_DB_NAME PANEL_APP_DB_USER
        return 0
    fi

    PANEL_DB_PROVISIONER_USER="panel_provisioner"
    PANEL_DB_PROVISIONER_PASS="$(generate_password)"
    PANEL_APP_DB_NAME="server_panel"
    PANEL_APP_DB_USER="panel_app"
    PANEL_APP_DB_PASS="$(generate_password)"

    export PANEL_DB_PROVISIONER_USER PANEL_DB_PROVISIONER_PASS
    export PANEL_APP_DB_NAME PANEL_APP_DB_USER PANEL_APP_DB_PASS

    local SQL_FILE
    SQL_FILE=$(mktemp)
    cat > "$SQL_FILE" <<SQL
-- Provisioner account: allowed to create/drop databases & users, grant privileges
-- (used exclusively by the panel's DatabaseService for tenant DB management).
-- MariaDB requires a grantor to already POSSESS a privilege before it can
-- pass that privilege on to someone else - WITH GRANT OPTION alone does not
-- bypass this. ALL PRIVILEGES (not just the admin/DDL subset) is required
-- here so db_grant_all()'s "GRANT ALL PRIVILEGES ON tenant_db.* TO
-- tenant_user" (used by App Installer and the Database menu) actually
-- succeeds instead of failing with "Access denied ... to database".
CREATE USER IF NOT EXISTS '${PANEL_DB_PROVISIONER_USER}'@'localhost' IDENTIFIED BY '${PANEL_DB_PROVISIONER_PASS}';
GRANT ALL PRIVILEGES ON *.* TO '${PANEL_DB_PROVISIONER_USER}'@'localhost' WITH GRANT OPTION;
ALTER USER '${PANEL_DB_PROVISIONER_USER}'@'localhost' IDENTIFIED BY '${PANEL_DB_PROVISIONER_PASS}';

-- Panel metadata database (stores websites, apps, users of the panel itself)
CREATE DATABASE IF NOT EXISTS \`${PANEL_APP_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${PANEL_APP_DB_USER}'@'localhost' IDENTIFIED BY '${PANEL_APP_DB_PASS}';
ALTER USER '${PANEL_APP_DB_USER}'@'localhost' IDENTIFIED BY '${PANEL_APP_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${PANEL_APP_DB_NAME}\`.* TO '${PANEL_APP_DB_USER}'@'localhost';

FLUSH PRIVILEGES;
SQL

    if spinner_run "Membuat akun database panel" -- mysql -u root < "$SQL_FILE"; then
        log_ok "Akun database panel berhasil dibuat"
    else
        die "Gagal membuat akun database panel. Periksa log: $INSTALL_LOG_FILE"
    fi
    rm -f "$SQL_FILE"

    state_mark "mariadb:accounts_created"
}

module_mariadb_import_schema() {
    log_step "Import skema database Panel"
    local schema_file="${SCRIPT_DIR}/sql/schema.sql"

    if [[ ! -f "$schema_file" ]]; then
        die "File skema tidak ditemukan: $schema_file"
    fi

    if mysql -u root "$PANEL_APP_DB_NAME" -e "SHOW TABLES;" 2>/dev/null | grep -q "panel_users"; then
        log_ok "Skema database panel sudah ada, lewati import"
    else
        spinner_run "Import sql/schema.sql" -- mysql -u root "$PANEL_APP_DB_NAME" < "$schema_file"
    fi
    state_mark "mariadb:schema_imported"
}

# Applies every file under sql/migrations/ - unlike module_mariadb_import_schema
# (which only ever runs on a fresh DB, gated by state_has), this runs on
# EVERY install.sh execution and every `yp repair panel`. Each migration
# file is expected to be idempotent on its own (CREATE TABLE IF NOT EXISTS,
# etc.) so there is no separate state tracking of which migrations already
# ran - this keeps a single source of truth instead of a schema.sql copy
# and a migrations copy drifting apart over time.
module_mariadb_run_migrations() {
    log_step "Menerapkan migrasi skema tambahan"
    local dir="${SCRIPT_DIR}/sql/migrations"
    if [[ ! -d "$dir" ]]; then
        log_ok "Tidak ada migrasi tambahan"
        return 0
    fi
    local f
    for f in "$dir"/*.sql; do
        [[ -e "$f" ]] || continue
        spinner_run "Migrasi: $(basename "$f")" -- mysql -u root "$PANEL_APP_DB_NAME" < "$f"
    done
}

module_mariadb_run_all() {
    module_mariadb_install
    module_mariadb_secure
    module_mariadb_create_accounts
    module_mariadb_import_schema
    module_mariadb_run_migrations
}
