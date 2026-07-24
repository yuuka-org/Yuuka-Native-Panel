#!/usr/bin/env bash
# ==============================================================================
# panel-exec.sh - THE sole privilege boundary between the panel (running as
# the unprivileged 'panel' user) and root-level system operations.
#
# Invoked ONLY via: sudo /opt/server-panel/scripts/panel-exec.sh <subcommand> [args...]
# The sudoers rule (installed by modules/panel.sh) restricts the 'panel' user
# to executing exactly this script as root, nothing else.
#
# Design rules (do not weaken):
#   - Fixed whitelist of subcommands (case statement below). Unknown
#     subcommand => exit 2, nothing executed.
#   - Every argument is validated against a strict regex BEFORE use.
#   - No eval. No unquoted variable expansion in executed commands.
#   - File paths are always re-derived from validated identifiers and
#     confined under a fixed base directory (realpath prefix check) -
#     never taken as a raw path from the caller.
#   - Bulk content (nginx config, PM2 ecosystem file) is read from STDIN,
#     never from argv, to avoid argv-length/quoting foot-guns.
#   - Every invocation is appended to the audit log with timestamp, caller
#     uid and subcommand - never with secret payloads (env values, tokens).
# ==============================================================================
set -euo pipefail
umask 027

AUDIT_LOG="/opt/server-panel/storage/logs/panel-exec-audit.log"
NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
WWW_BASE="/var/www"
NODEAPPS_BASE="/home/nodeapps/apps"
NODEAPPS_HOME="/home/nodeapps"
BACKUP_BASE="/opt/server-panel/storage/backups"
ACME_WEBROOT="/var/www/_letsencrypt"
INSTALLER_DIR="/opt/yuuka-installer"
SELF_UPDATE_LOG="/opt/server-panel/storage/logs/self-update.log"

mkdir -p "$(dirname "$AUDIT_LOG")"
audit() {
    echo "$(date -Iseconds) uid=$(id -u) caller=${SUDO_USER:-unknown} subcommand=$1 status=$2" >> "$AUDIT_LOG"
}

fail() {
    echo "ERROR: $1" >&2
    audit "${SUBCOMMAND:-unknown}" "error:$1"
    exit 1
}

# ---------------------------------------------------------------------------
# Validators - exit non-zero (via fail) on mismatch
# ---------------------------------------------------------------------------
require_match() {
    local value="$1" pattern="$2" label="$3"
    [[ "$value" =~ $pattern ]] || fail "Argumen tidak valid untuk ${label}: '${value}'"
}

require_path_within() {
    # require_path_within <path> <base-dir>
    local path="$1" base="$2"
    local resolved
    resolved=$(realpath -m -- "$path")
    local resolved_base
    resolved_base=$(realpath -m -- "$base")
    case "$resolved" in
        "$resolved_base"/*) ;;
        *) fail "Path di luar batas yang diizinkan: $path" ;;
    esac
    printf '%s' "$resolved"
}

RE_SITENAME='^[a-zA-Z0-9._-]{1,200}$'
RE_APPNAME='^[a-zA-Z0-9_-]{1,64}$'
RE_DOMAIN='^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$'
RE_EMAIL='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
RE_DBNAME='^[a-zA-Z0-9_]{1,64}$'
RE_LINES='^[0-9]{1,4}$'
RE_PORT='^[0-9]{1,5}$'
# Same whitelist op_service_status already enforces via its own case
# statement - factored into a regex here for op_service_restart, which is
# a mutating action and deserves the exact same explicit require_match
# pattern used everywhere else in this file.
RE_RESTARTABLE_SERVICE='^(nginx|mariadb|cloudflared|php7\.4-fpm|php8\.0-fpm|php8\.1-fpm|php8\.2-fpm|php8\.3-fpm|php8\.4-fpm)$'

# ---------------------------------------------------------------------------
# Nginx operations
# ---------------------------------------------------------------------------
op_nginx_test() {
    nginx -t
}

op_nginx_reload() {
    nginx -t
    systemctl reload nginx
}

op_nginx_write_config() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    local target="${NGINX_AVAILABLE}/${site}.conf"
    require_path_within "$target" "$NGINX_AVAILABLE" >/dev/null

    local tmp
    tmp=$(mktemp)
    cat > "$tmp"

    if [[ ! -s "$tmp" ]]; then
        rm -f "$tmp"
        fail "Konten konfigurasi kosong"
    fi

    local previous_backup=""
    if [[ -f "$target" ]]; then
        previous_backup=$(mktemp)
        cp -a "$target" "$previous_backup"
    fi

    mv "$tmp" "$target"
    chown root:root "$target"
    chmod 644 "$target"

    if ! nginx -t 2>/tmp/nginx-test-err.$$; then
        if [[ -n "$previous_backup" ]]; then
            mv "$previous_backup" "$target"
        else
            rm -f "$target"
        fi
        local err
        err=$(cat /tmp/nginx-test-err.$$ 2>/dev/null || true)
        rm -f "/tmp/nginx-test-err.$$"
        fail "nginx -t gagal, konfigurasi dibatalkan: ${err}"
    fi
    rm -f "/tmp/nginx-test-err.$$" "$previous_backup" 2>/dev/null || true
    echo "OK: konfigurasi ${site} ditulis dan valid"
}

op_nginx_enable() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    local src="${NGINX_AVAILABLE}/${site}.conf"
    local dst="${NGINX_ENABLED}/${site}.conf"
    [[ -f "$src" ]] || fail "Konfigurasi ${site} tidak ditemukan"
    ln -sf "$src" "$dst"
    if ! nginx -t; then
        rm -f "$dst"
        fail "nginx -t gagal setelah enable, dibatalkan"
    fi
    systemctl reload nginx
    echo "OK: ${site} enabled"
}

op_nginx_disable() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    local dst="${NGINX_ENABLED}/${site}.conf"
    rm -f "$dst"
    nginx -t
    systemctl reload nginx
    echo "OK: ${site} disabled"
}

op_nginx_delete() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    rm -f "${NGINX_ENABLED}/${site}.conf" "${NGINX_AVAILABLE}/${site}.conf"
    nginx -t
    systemctl reload nginx
    echo "OK: ${site} deleted"
}

# ---------------------------------------------------------------------------
# PM2 / Node.js operations - always executed as the 'nodeapps' user
# ---------------------------------------------------------------------------
as_nodeapps() {
    # cd into a directory 'nodeapps' can always access first: this process
    # inherits whatever cwd panel-exec.sh itself was started with (which may
    # be root-owned and unreadable by 'nodeapps', e.g. an operator's shell
    # cwd during manual debugging, or an unrelated PHP-FPM worker cwd). If
    # left inherited, Node/libuv's child-process setup fails to resolve the
    # working directory and PM2 reports this as a misleading "spawn EACCES"
    # that looks like a permission problem on the node binary itself.
    runuser -u nodeapps -- bash -lc "cd '${NODEAPPS_HOME}' && export NVM_DIR='${NODEAPPS_HOME}/.nvm'; [ -s \"\$NVM_DIR/nvm.sh\" ] && . \"\$NVM_DIR/nvm.sh\"; $*"
}

op_pm2_deploy() {
    local app="$1"
    require_match "$app" "$RE_APPNAME" "appname"
    local app_dir="${NODEAPPS_BASE}/${app}"
    require_path_within "$app_dir" "$NODEAPPS_BASE" >/dev/null

    mkdir -p "$app_dir"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    [[ -s "$tmp" ]] || { rm -f "$tmp"; fail "Ecosystem config kosong"; }

    mv "$tmp" "${app_dir}/ecosystem.config.js"
    chown -R nodeapps:nodeapps "$app_dir"
    chmod 750 "$app_dir"
    chmod 640 "${app_dir}/ecosystem.config.js"

    as_nodeapps "pm2 start '${app_dir}/ecosystem.config.js' --update-env"
    as_nodeapps "pm2 save"
    echo "OK: ${app} deployed via PM2"
}

op_pm2_start() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 start '${app}'"
    as_nodeapps "pm2 save"
}

op_pm2_stop() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 stop '${app}'"
    as_nodeapps "pm2 save"
}

op_pm2_restart() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 restart '${app}'"
}

op_pm2_reload() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 reload '${app}'"
}

op_pm2_delete() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 delete '${app}'" || true
    as_nodeapps "pm2 save"
}

op_pm2_jlist() {
    as_nodeapps "pm2 jlist"
}

op_pm2_describe() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 describe '${app}'"
}

op_pm2_logs() {
    local app="$1" lines="${2:-100}"
    require_match "$app" "$RE_APPNAME" "appname"
    require_match "$lines" "$RE_LINES" "lines"
    [[ "$lines" -le 1000 ]] || lines=1000
    as_nodeapps "pm2 logs '${app}' --lines ${lines} --nostream"
}

op_pm2_flush() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 flush '${app}'"
}

op_pm2_save() {
    as_nodeapps "pm2 save"
}

# ---------------------------------------------------------------------------
# Certbot / SSL
# ---------------------------------------------------------------------------
op_certbot_issue() {
    local domain="$1" email="$2"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_match "$email" "$RE_EMAIL" "email"
    certbot certonly --webroot -w "$ACME_WEBROOT" -d "$domain" \
        --non-interactive --agree-tos -m "$email" --no-eff-email
}

op_certbot_remove() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    certbot delete --cert-name "$domain" --non-interactive
}

# ---------------------------------------------------------------------------
# Service status (whitelist only - never arbitrary systemctl targets)
# ---------------------------------------------------------------------------
op_service_status() {
    local svc="$1"
    case "$svc" in
        nginx|mariadb|cloudflared) ;;
        php7.4-fpm|php8.0-fpm|php8.1-fpm|php8.2-fpm|php8.3-fpm|php8.4-fpm) ;;
        *) fail "Service tidak diizinkan: $svc" ;;
    esac
    systemctl is-active "$svc" 2>/dev/null || true
}

# Deferred via systemd-run (NOT `& disown`) - a plain backgrounded job stays
# in the calling PHP-FPM pool's cgroup, and that pool's unit uses systemd's
# default KillMode=control-group, which kills every process still in that
# cgroup (reparenting to PID 1 does NOT change cgroup membership) the
# moment the pool itself is restarted. Since restarting THIS pool is
# exactly what this operation can trigger (transitively, via
# service-restart on the panel's own php-fpm, or via installer-self-update
# -> update.sh -> yp repair panel), a plain `&` job would be killed
# mid-restart. `systemd-run` places the job in its own transient unit under
# system.slice, fully decoupled from the caller's cgroup - the only
# primitive here that is actually immune to that kill.
op_service_restart() {
    local svc="$1"
    require_match "$svc" "$RE_RESTARTABLE_SERVICE" "service"
    local unit="yuuka-panel-restart-$(echo "$svc" | tr -c 'a-zA-Z0-9' '-')"
    systemd-run --unit="$unit" --collect \
        --description="Yuuka Panel: restart ${svc}" \
        -- /bin/bash -c "sleep 1; systemctl restart '${svc}'" \
        || fail "Gagal menjadwalkan restart ${svc}"
    echo "OK: restart ${svc} dijadwalkan"
}

# ---------------------------------------------------------------------------
# Installer self-update - version info / update-check are read-only;
# self-update actually runs update.sh (the same script an operator already
# runs manually over SSH) rather than reimplementing its steps here, so a
# fix shipped in update.sh/modules/*.sh/yp itself is never silently skipped
# by a UI-triggered update (see plan notes: `yp update` alone never
# reinstalls /usr/local/bin/yp, only update.sh's
# module_panel_setup_installer_copy does).
# ---------------------------------------------------------------------------
op_installer_version_info() {
    local commit="" commit_date=""
    if [[ -d "${INSTALLER_DIR}/.git" ]]; then
        commit=$(git -C "$INSTALLER_DIR" rev-parse --short HEAD 2>/dev/null)
        commit_date=$(git -C "$INSTALLER_DIR" log -1 --format=%cd --date=short 2>/dev/null)
    fi
    echo "commit:${commit}"
    echo "commit_date:${commit_date}"
    echo "nginx:$(nginx -v 2>&1 | sed 's/nginx version: //')"
    echo "mariadb:$(mariadb --version 2>/dev/null)"
    echo "cloudflared:$(cloudflared --version 2>/dev/null | head -1)"
}

op_installer_check_update() {
    [[ -d "${INSTALLER_DIR}/.git" ]] || fail "Installer bukan git clone"
    GIT_TERMINAL_PROMPT=0 git -C "$INSTALLER_DIR" fetch --quiet origin \
        || fail "git fetch gagal (cek koneksi/kredensial di server)"
    local behind
    behind=$(git -C "$INSTALLER_DIR" rev-list HEAD..origin/master --count 2>/dev/null) || behind="0"
    echo "behind:${behind}"
}

op_installer_self_update_status() {
    systemctl is-active yuuka-panel-self-update.service 2>/dev/null || true
}

op_installer_self_update() {
    [[ -d "${INSTALLER_DIR}/.git" ]] || fail "Installer bukan git clone"

    # Fast-forward only, checked BEFORE update.sh is ever invoked - a
    # non-linear history (needs a real merge) or a stuck credential prompt
    # must fail clean here, not partway through update.sh with the panel
    # pool possibly already mid-restart.
    GIT_TERMINAL_PROMPT=0 git -C "$INSTALLER_DIR" fetch --quiet origin \
        || fail "git fetch gagal (cek koneksi/kredensial di server)"
    git -C "$INSTALLER_DIR" merge --ff-only --quiet \
        || fail "git merge --ff-only gagal - riwayat tidak linear, perlu penanganan manual lewat SSH"

    mkdir -p "$(dirname "$SELF_UPDATE_LOG")"

    # --collect (CollectMode=inactive-or-failed) auto-removes the unit once
    # it finishes - and a fixed --unit name IS the lock: systemd-run refuses
    # to start a second unit with the same name while one is still active,
    # so there is no separate lock file to go stale.
    # NOTE: \$(date ...) and \$? below are escaped so they're evaluated
    # INSIDE the scheduled bash -c at its own run time - if left
    # unescaped, panel-exec.sh's own shell would substitute them
    # immediately while building this string, making both timestamps (and
    # the "exit=" code) reflect the moment the update was SCHEDULED, not
    # when it actually started/finished.
    systemd-run --unit=yuuka-panel-self-update --collect \
        --description="Yuuka Panel self-update" \
        -- /bin/bash -c "export NONINTERACTIVE=1; { echo \"=== \$(date -Iseconds) update dimulai ===\"; timeout 900 bash '${INSTALLER_DIR}/update.sh'; echo \"=== \$(date -Iseconds) update selesai (exit=\$?) ===\"; } >>'${SELF_UPDATE_LOG}' 2>&1" \
        < /dev/null \
        || fail "Update sudah berjalan (unit yuuka-panel-self-update masih aktif) atau gagal dijadwalkan"
    echo "OK: update dimulai di background, log: ${SELF_UPDATE_LOG}"
}

# ---------------------------------------------------------------------------
# Database backup / restore (mysqldump runs as root via unix_socket auth)
# ---------------------------------------------------------------------------
op_mysqldump_db() {
    local db="$1" outfile="$2"
    require_match "$db" "$RE_DBNAME" "dbname"
    require_path_within "$outfile" "$BACKUP_BASE" >/dev/null
    mkdir -p "$(dirname "$outfile")"
    mysqldump --single-transaction --routines --triggers -u root "$db" > "$outfile"
    chown panel:panel "$outfile"
    chmod 640 "$outfile"
    echo "OK: backup ${db} -> ${outfile}"
}

op_mysql_restore_db() {
    local db="$1" infile="$2"
    require_match "$db" "$RE_DBNAME" "dbname"
    require_path_within "$infile" "$BACKUP_BASE" >/dev/null
    [[ -f "$infile" ]] || fail "File backup tidak ditemukan: $infile"
    mysql -u root "$db" < "$infile"
    echo "OK: restore ${db} <- ${infile}"
}

# ---------------------------------------------------------------------------
# Cloudflared control
# ---------------------------------------------------------------------------
op_cloudflared_status() {
    systemctl is-active cloudflared 2>/dev/null || true
}
op_cloudflared_restart() { systemctl restart cloudflared; }
op_cloudflared_stop()    { systemctl stop cloudflared; }
op_cloudflared_start()   { systemctl start cloudflared; }
op_cloudflared_version() {
    cloudflared --version 2>/dev/null | head -1 || true
}

# ---------------------------------------------------------------------------
# Filesystem helpers (confined to fixed base directories)
# ---------------------------------------------------------------------------
op_fs_mkdir_website() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    mkdir -p "${dir}/public"
    chown -R www-data:www-data "$dir"
    chmod 750 "$dir"
    echo "$dir"
}

op_fs_remove_website() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    [[ "$dir" == "$WWW_BASE" ]] && fail "Refusing to remove base directory"
    rm -rf -- "$dir"
    echo "OK: removed $dir"
}

op_fs_remove_nodeapp() {
    local app="$1"
    require_match "$app" "$RE_APPNAME" "appname"
    local dir="${NODEAPPS_BASE}/${app}"
    require_path_within "$dir" "$NODEAPPS_BASE" >/dev/null
    [[ "$dir" == "$NODEAPPS_BASE" ]] && fail "Refusing to remove base directory"
    rm -rf -- "$dir"
    echo "OK: removed $dir"
}

op_disk_usage() {
    # Emits: total_bytes used_bytes avail_bytes for the root filesystem.
    # Not privileged (df needs no root), but routed through this audited
    # channel for consistency - the panel PHP-FPM pool's open_basedir does
    # not include '/', so it cannot call disk_total_space() itself.
    df -B1 --output=size,used,avail / | tail -n 1
}

op_port_check() {
    local port="$1"
    require_match "$port" "$RE_PORT" "port"
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ":${port}\$"; then
        echo "listening"
    else
        echo "free"
    fi
}

# ---------------------------------------------------------------------------
# File Manager - browse/upload/download/edit/extract, scoped to either a
# website's document root (${WWW_BASE}/<domain>) or a node app's project
# directory (${NODEAPPS_BASE}/<app>). Filenames are NOT restricted to a
# strict charset (real-world uploads/zips legitimately contain spaces and
# unicode) - the actual escape-prevention guarantee is realpath containment
# (require_path_within), applied to every resolved path before use, exactly
# like the rest of this script.
# ---------------------------------------------------------------------------
RE_FM_SCOPE='^(website|nodeapp|www|nodeapps)$'
FM_MAX_READ_BYTES=209715200  # 200MB hard backstop, independent of the
                             # app-level FILEMANAGER_MAX_UPLOAD_MB cap in
                             # .env - protects PHP-FPM memory even if that
                             # softer limit is ever bypassed or misconfigured.

fm_require_safe_relpath() {
    local value="$1" label="$2"
    if [[ "$value" == *".."* ]]; then
        fail "Path tidak valid untuk ${label}: mengandung '..'"
    fi
    if [[ "$value" == /* ]]; then
        fail "Path tidak valid untuk ${label}: tidak boleh path absolut"
    fi
    if [[ ${#value} -gt 4096 ]]; then
        fail "Path tidak valid untuk ${label}: terlalu panjang"
    fi
}

fm_require_basename() {
    local value="$1" label="$2"
    if [[ -z "$value" || "$value" == "." || "$value" == ".." || "$value" == */* ]]; then
        fail "Nama tidak valid untuk ${label}: '${value}'"
    fi
}

fm_owner_for_scope() {
    case "$1" in
        website|www)      printf 'www-data:www-data' ;;
        nodeapp|nodeapps) printf 'nodeapps:nodeapps' ;;
    esac
}

# "www"/"nodeapps" are root-browse scopes (Explorer-style: no specific
# website/app needs to be picked first, "name" is ignored) - used by the
# two "Jelajahi semua" entries in the File Manager picker.
fm_is_root_scope() {
    [[ "$1" == "www" || "$1" == "nodeapps" ]]
}

# fm_resolve_base <scope> <name> -> prints absolute (realpath-canonicalized)
# base directory, verified to exist and be confined under the scope's fixed
# root. Returns the realpath'd form (not the raw concatenation) so that
# later exact-match comparisons (e.g. "is target == base") are comparing
# like with like.
fm_resolve_base() {
    local scope="$1" name="$2" dir="" resolved=""
    case "$scope" in
        website)
            require_match "$name" "$RE_DOMAIN" "domain"
            dir="${WWW_BASE}/${name}"
            resolved=$(require_path_within "$dir" "$WWW_BASE")
            ;;
        nodeapp)
            require_match "$name" "$RE_APPNAME" "appname"
            dir="${NODEAPPS_BASE}/${name}"
            resolved=$(require_path_within "$dir" "$NODEAPPS_BASE")
            ;;
        www)
            # Root-browse scope: base IS WWW_BASE itself, "name" ignored.
            resolved=$(realpath -m -- "$WWW_BASE")
            ;;
        nodeapps)
            resolved=$(realpath -m -- "$NODEAPPS_BASE")
            ;;
        *)
            fail "Scope tidak dikenal: $scope"
            ;;
    esac
    [[ -d "$resolved" ]] || fail "Direktori scope tidak ditemukan: $resolved"
    printf '%s' "$resolved"
}

# fm_resolve_target <scope> <name> <relpath> -> prints absolute resolved
# target path, guaranteed confined under the scope's base dir (does NOT
# require the target to already exist - safe for mkdir/write of new paths).
# Empty relpath means "the scope root itself" - handled as a direct
# short-circuit because require_path_within() only accepts paths STRICTLY
# nested under base (base itself does not match "$base/*"), so calling it
# with target==base would incorrectly fail even though base was already
# validated by fm_resolve_base() above.
fm_resolve_target() {
    local scope="$1" name="$2" relpath="$3" base=""
    require_match "$scope" "$RE_FM_SCOPE" "scope"
    fm_require_safe_relpath "$relpath" "path"
    base=$(fm_resolve_base "$scope" "$name")
    if [[ -z "$relpath" ]]; then
        printf '%s' "$base"
        return 0
    fi
    require_path_within "${base}/${relpath}" "$base"
}

op_files_list() {
    local scope="$1" name="$2" relpath="${3:-}"
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    [[ -d "$target" ]] || fail "Direktori tidak ditemukan: $relpath"
    # NUL-terminated (\0), NOT newline - a filename legally containing a
    # literal \n byte (Linux allows any byte except NUL and /) could
    # otherwise make ONE find record look like TWO rows once explode()'d
    # on "\n" in PHP, one of them fully attacker-controlled (fake type/
    # size/name) - already reachable today via "Upload & Extract ZIP"
    # (zip entry names can contain \n). NUL is the one byte that truly
    # cannot appear in a filename, closing this rather than narrowing it.
    # .trash is Recycle Bin storage (see op_files_delete) - never shown
    # in normal listings regardless of caller.
    find "$target" -mindepth 1 -maxdepth 1 -not -name '.trash' -printf '%y\t%s\t%T@\t%m\t%f\0' 2>/dev/null
}

op_files_read() {
    local scope="$1" name="$2" relpath="$3"
    [[ -n "$relpath" ]] || fail "Path file wajib diisi"
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    [[ -f "$target" ]] || fail "File tidak ditemukan: $relpath"
    local size
    size=$(stat -c%s "$target" 2>/dev/null || echo 0)
    [[ "$size" -le "$FM_MAX_READ_BYTES" ]] || fail "File terlalu besar untuk dibuka lewat File Manager (${size} bytes)"
    cat -- "$target"
}

op_files_write() {
    local scope="$1" name="$2" relpath="$3"
    [[ -n "$relpath" ]] || fail "Path file wajib diisi"
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    local base
    base=$(fm_resolve_base "$scope" "$name")
    [[ "$target" == "$base" ]] && fail "Path file tidak valid"

    local tmp parent_dir owner
    tmp=$(mktemp)
    cat > "$tmp"

    parent_dir="$(dirname "$target")"
    owner=$(fm_owner_for_scope "$scope")
    if [[ ! -d "$parent_dir" ]]; then
        mkdir -p "$parent_dir"
        chown -R "$owner" "$parent_dir"
    fi
    mv "$tmp" "$target"
    chown "$owner" "$target"
    chmod 640 "$target"
    echo "OK: written $relpath"
}

op_files_mkdir() {
    local scope="$1" name="$2" relpath="$3"
    [[ -n "$relpath" ]] || fail "Nama folder wajib diisi"
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    mkdir -p "$target"
    local owner
    owner=$(fm_owner_for_scope "$scope")
    chown -R "$owner" "$target"
    echo "OK: mkdir $relpath"
}

fm_encode_trash_name() {
    printf '%s' "$1" | tr '/' '__'
}

# Soft-delete: moves into a hidden ${base}/.trash/ (Recycle Bin) instead of
# rm -rf, restorable via op_files_trash_restore. Deleting something that's
# ALREADY inside .trash (relpath starts with ".trash") means "permanently
# empty this one item" instead - that's how op_files_trash_delete reuses
# this same function rather than duplicating the rm -rf logic.
op_files_delete() {
    local scope="$1" name="$2" relpath="$3"
    [[ -n "$relpath" ]] || fail "Refusing to delete scope root"
    if fm_is_root_scope "$scope" && [[ "$relpath" != */* ]]; then
        fail "Tidak bisa menghapus folder website/aplikasi lewat mode 'Jelajahi semua' - gunakan menu Hapus Website/Aplikasi supaya database & konfigurasi ikut dibersihkan"
    fi
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    local base
    base=$(fm_resolve_base "$scope" "$name")
    [[ "$target" == "$base" ]] && fail "Refusing to delete scope root"
    [[ -e "$target" ]] || fail "Target tidak ditemukan: $relpath"

    case "$relpath" in
        .trash|.trash/*)
            rm -rf -- "$target"
            echo "OK: permanently deleted $relpath"
            return 0
            ;;
    esac

    local owner trash_dir trash_name
    owner=$(fm_owner_for_scope "$scope")
    trash_dir="${base}/.trash"
    mkdir -p "$trash_dir"
    chown "$owner" "$trash_dir"
    chmod 750 "$trash_dir"

    trash_name="$(date +%Y%m%d%H%M%S%N)_$$_$(fm_encode_trash_name "$relpath")"
    mv -- "$target" "${trash_dir}/${trash_name}"
    printf '%s' "$relpath" > "${trash_dir}/${trash_name}.origpath"
    chown "$owner" "${trash_dir}/${trash_name}.origpath"
    echo "OK: moved to trash: $relpath"
}

op_files_rename() {
    local scope="$1" name="$2" relpath="$3" newbasename="$4"
    [[ -n "$relpath" ]] || fail "Path sumber wajib diisi"
    fm_require_basename "$newbasename" "nama baru"
    if fm_is_root_scope "$scope" && [[ "$relpath" != */* ]]; then
        fail "Tidak bisa mengganti nama folder website/aplikasi lewat mode 'Jelajahi semua' - itu akan memutus koneksi ke domain/PM2 yang sudah terdaftar"
    fi
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    [[ -e "$target" ]] || fail "Target tidak ditemukan: $relpath"
    local base
    base=$(fm_resolve_base "$scope" "$name")
    [[ "$target" == "$base" ]] && fail "Refusing to rename scope root"

    local parent dest
    parent=$(dirname "$target")
    dest="${parent}/${newbasename}"
    require_path_within "$dest" "$base" >/dev/null
    [[ -e "$dest" ]] && fail "Sudah ada file/folder dengan nama itu"
    mv -- "$target" "$dest"
    echo "OK: renamed to $newbasename"
}

op_files_extract_zip() {
    local scope="$1" name="$2" relpath="${3:-}"
    require_match "$scope" "$RE_FM_SCOPE" "scope"
    fm_require_safe_relpath "$relpath" "path"
    local base
    base=$(fm_resolve_base "$scope" "$name")
    local target_dir="$base"
    if [[ -n "$relpath" ]]; then
        target_dir=$(require_path_within "${base}/${relpath}" "$base")
    fi
    mkdir -p "$target_dir"

    local tmp_zip
    tmp_zip=$(mktemp --suffix=.zip)
    cat > "$tmp_zip"
    if [[ ! -s "$tmp_zip" ]]; then
        rm -f "$tmp_zip"
        fail "File ZIP kosong"
    fi

    local tmp_extract
    tmp_extract=$(mktemp -d)

    if ! unzip -q -o "$tmp_zip" -d "$tmp_extract"; then
        rm -f "$tmp_zip"
        rm -rf "$tmp_extract"
        fail "Gagal mengekstrak ZIP (format tidak valid atau rusak)"
    fi
    rm -f "$tmp_zip"

    # Zip-slip guard: verify every extracted entry's realpath is still
    # confined under tmp_extract BEFORE copying anything into the real
    # target directory - protects against crafted archives with symlink
    # entries or traversal sequences that a given unzip build might not
    # fully sanitize on its own.
    local escaped=0 entry resolved
    while IFS= read -r -d '' entry; do
        resolved=$(realpath -m -- "$entry")
        case "$resolved" in
            "$tmp_extract"/*) ;;
            *) escaped=1 ;;
        esac
    done < <(find "$tmp_extract" -mindepth 1 -print0)

    if [[ "$escaped" -eq 1 ]]; then
        rm -rf "$tmp_extract"
        fail "ZIP ditolak: berisi entry yang mencoba keluar dari direktori tujuan"
    fi

    shopt -s dotglob nullglob
    cp -a "$tmp_extract"/* "$target_dir"/
    shopt -u dotglob nullglob
    rm -rf "$tmp_extract"

    local owner
    owner=$(fm_owner_for_scope "$scope")
    chown -R "$owner" "$target_dir"
    echo "OK: extracted zip to ${relpath:-/}"
}

# Normalizes a File Manager scope to its "family" (website vs nodeapp) -
# www/nodeapps (root-browse) are just variants of the same family as
# website/nodeapp. Used to gate cross-scope copy/move below: every
# website is www-data:www-data regardless of domain, every node app is
# nodeapps:nodeapps regardless of name (no per-site/per-tenant Unix user
# isolation exists anywhere in this codebase) - so copying/moving between
# two DIFFERENT websites (or two different node apps) never crosses a
# Unix ownership boundary, only website<->nodeapp would.
fm_scope_family() {
    case "$1" in
        website|www)      printf 'website' ;;
        nodeapp|nodeapps) printf 'nodeapp' ;;
        *) fail "Scope tidak dikenal: $1" ;;
    esac
}

_fm_copy_or_move() {
    local mode="$1" src_scope="$2" src_name="$3" src_relpath="$4" dest_scope="$5" dest_name="$6" dest_relpath="$7"

    require_match "$src_scope" "$RE_FM_SCOPE" "src scope"
    require_match "$dest_scope" "$RE_FM_SCOPE" "dest scope"
    [[ -n "$src_relpath" ]] || fail "Path sumber wajib diisi"
    [[ -n "$dest_relpath" ]] || fail "Path tujuan wajib diisi"

    local src_family dest_family
    src_family=$(fm_scope_family "$src_scope")
    dest_family=$(fm_scope_family "$dest_scope")
    [[ "$src_family" == "$dest_family" ]] || fail "Tidak bisa memindahkan/menyalin antara Website dan Node.js App"

    if fm_is_root_scope "$src_scope" && [[ "$src_relpath" != */* ]]; then
        fail "Tidak bisa memindahkan/menyalin folder website/aplikasi lewat mode 'Jelajahi semua' - gunakan menu Hapus/Kelola Website/Aplikasi"
    fi
    case "$src_relpath" in
        .trash|.trash/*) fail "Tidak bisa menyalin/memindahkan isi Recycle Bin" ;;
    esac

    local src_target src_base
    src_target=$(fm_resolve_target "$src_scope" "$src_name" "$src_relpath")
    src_base=$(fm_resolve_base "$src_scope" "$src_name")
    [[ "$src_target" == "$src_base" ]] && fail "Tidak bisa memindahkan/menyalin folder utama"
    [[ -e "$src_target" ]] || fail "Sumber tidak ditemukan: $src_relpath"

    local dest_target dest_base
    dest_target=$(fm_resolve_target "$dest_scope" "$dest_name" "$dest_relpath")
    dest_base=$(fm_resolve_base "$dest_scope" "$dest_name")
    [[ -e "$dest_target" ]] && fail "Sudah ada item di tujuan: $dest_relpath"

    # Refuse a self-referential copy/move (dest nested inside src) - `cp
    # -a` into your own descendant can recurse into corrupted/unbounded
    # output rather than failing cleanly.
    local src_target_slash="${src_target}/"
    case "${dest_target}/" in
        "$src_target_slash"*) fail "Tujuan tidak boleh berada di dalam sumber itu sendiri" ;;
    esac

    mkdir -p "$(dirname "$dest_target")"
    if [[ "$mode" == "copy" ]]; then
        cp -a -- "$src_target" "$dest_target"
    else
        mv -- "$src_target" "$dest_target"
    fi

    local dest_owner
    dest_owner=$(fm_owner_for_scope "$dest_scope")
    chown -R "$dest_owner" "$dest_target"
    echo "OK: ${mode} $src_relpath -> $dest_relpath"
}

op_files_copy() { _fm_copy_or_move "copy" "$@"; }
op_files_move() { _fm_copy_or_move "move" "$@"; }

RE_CHMOD_MODE='^[0-7][0-7][0-7]$'

# Mode must be EXACTLY 3 octal digits - this structurally rejects a 4th
# digit (setuid/setgid/sticky bit) via the regex itself, not just "not
# offered in the UI". The last digit (other/world) may not have the
# write bit set (2/3/6/7 rejected) - owner and group here are ALWAYS the
# shared service account (www-data or nodeapps, never a per-site user),
# so restricting those digits wouldn't add any real isolation; "other" is
# the one class that genuinely includes a different principal (another
# site/app's owner if ever isolated, or unrelated system processes).
op_files_chmod() {
    local scope="$1" name="$2" relpath="$3" mode="$4"
    require_match "$mode" "$RE_CHMOD_MODE" "mode"
    case "${mode: -1}" in
        2|3|6|7) fail "Mode tidak diizinkan: 'other' (dunia) tidak boleh punya izin tulis" ;;
    esac
    [[ -n "$relpath" ]] || fail "Path wajib diisi"
    if fm_is_root_scope "$scope" && [[ "$relpath" != */* ]]; then
        fail "Tidak bisa mengubah izin folder website/aplikasi lewat mode 'Jelajahi semua'"
    fi
    local target base
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    base=$(fm_resolve_base "$scope" "$name")
    [[ "$target" == "$base" ]] && fail "Tidak bisa mengubah izin folder utama"
    [[ -e "$target" ]] || fail "Target tidak ditemukan: $relpath"
    chmod "$mode" -- "$target"
    echo "OK: chmod $mode $relpath"
}

FM_SEARCH_TIMEOUT_SECONDS=20
FM_SEARCH_MAX_RESULTS=500

op_files_search() {
    local scope="$1" name="$2" query="$3"
    require_match "$scope" "$RE_FM_SCOPE" "scope"
    [[ -n "$query" ]] || fail "Kata kunci pencarian wajib diisi"
    [[ ${#query} -le 200 ]] || fail "Kata kunci terlalu panjang"
    local base
    base=$(fm_resolve_base "$scope" "$name")

    # Escapes find's OWN glob metacharacters (*, ?, [, ]) so a search for
    # e.g. "photo[1]" or "report*" is a literal substring match, not a
    # find(1) glob pattern - this is about search-result CORRECTNESS, not
    # a security boundary (Executor::run()'s array-form proc_open already
    # means $query never reaches a shell no matter what it contains).
    local escaped
    escaped=$(printf '%s' "$query" | sed 's/[]*?[]/\\&/g')

    # timeout wraps the actual find process here (server-side) rather
    # than relying on Executor::run()'s stream_set_timeout() alone, which
    # only stops PHP from waiting on the pipe - it does not guarantee the
    # sudo-spawned find itself gets killed. %P (path relative to $base,
    # not just the basename) since results can be nested anywhere.
    timeout "$FM_SEARCH_TIMEOUT_SECONDS" find "$base" -mindepth 1 \
        -not -path "${base}/.trash" -not -path "${base}/.trash/*" \
        -iname "*${escaped}*" \
        -printf '%y\t%s\t%T@\t%P\0' 2>/dev/null \
        | head -z -n "$FM_SEARCH_MAX_RESULTS"
}

# ---------------------------------------------------------------------------
# Recycle Bin - .trash/ lives INSIDE each scope's own base directory (see
# op_files_delete), never a centralized location, so it never crosses the
# Unix ownership boundary the rest of File Manager already respects.
# ---------------------------------------------------------------------------
op_files_trash_list() {
    local scope="$1" name="$2"
    local base trash_dir
    base=$(fm_resolve_base "$scope" "$name")
    trash_dir="${base}/.trash"
    [[ -d "$trash_dir" ]] || return 0

    local entry entry_base origpath mtime size type
    shopt -s nullglob
    for entry in "$trash_dir"/*; do
        case "$entry" in *.origpath) continue ;; esac
        entry_base=$(basename "$entry")
        origpath=""
        [[ -f "${entry}.origpath" ]] && origpath=$(cat "${entry}.origpath" 2>/dev/null)
        mtime=$(stat -c '%Y' "$entry" 2>/dev/null || echo 0)
        size=$(stat -c '%s' "$entry" 2>/dev/null || echo 0)
        type="f"
        [[ -d "$entry" ]] && type="d"
        printf '%s\t%s\t%s\t%s\t%s\0' "$type" "$size" "$mtime" "$entry_base" "$origpath"
    done
    shopt -u nullglob
}

op_files_trash_restore() {
    local scope="$1" name="$2" trash_entry="$3"
    fm_require_basename "$trash_entry" "trash entry"
    local base trash_dir target sidecar origpath dest
    base=$(fm_resolve_base "$scope" "$name")
    trash_dir="${base}/.trash"
    target="${trash_dir}/${trash_entry}"
    sidecar="${target}.origpath"
    [[ -e "$target" ]] || fail "Item trash tidak ditemukan: $trash_entry"
    [[ -f "$sidecar" ]] || fail "Info lokasi asal tidak ditemukan untuk: $trash_entry"
    origpath=$(cat "$sidecar")
    fm_require_safe_relpath "$origpath" "lokasi asal"
    # require_path_within (realpath-based containment), NOT just a string
    # '..' check - closes off traversal/symlink escape even from a
    # corrupted/crafted sidecar file, same guarantee the zip-slip guard
    # in op_files_extract_zip already relies on.
    dest=$(require_path_within "${base}/${origpath}" "$base")
    [[ -e "$dest" ]] && fail "Sudah ada item di lokasi asal ($origpath) - pindahkan/hapus dulu yang ada, baru restore"
    mkdir -p "$(dirname "$dest")"
    mv -- "$target" "$dest"
    rm -f "$sidecar"
    local owner
    owner=$(fm_owner_for_scope "$scope")
    chown -R "$owner" "$dest"
    echo "OK: restored $trash_entry -> $origpath"
}

op_files_trash_delete() {
    local scope="$1" name="$2" trash_entry="$3"
    fm_require_basename "$trash_entry" "trash entry"
    local base trash_dir target
    base=$(fm_resolve_base "$scope" "$name")
    trash_dir="${base}/.trash"
    target="${trash_dir}/${trash_entry}"
    [[ -e "$target" ]] || fail "Item trash tidak ditemukan: $trash_entry"
    rm -rf -- "$target" "${target}.origpath"
    echo "OK: permanently deleted $trash_entry"
}

op_files_trash_empty() {
    local scope="$1" name="$2"
    local base trash_dir
    base=$(fm_resolve_base "$scope" "$name")
    trash_dir="${base}/.trash"
    if [[ -d "$trash_dir" ]]; then
        shopt -s dotglob nullglob
        rm -rf -- "$trash_dir"/*
        shopt -u dotglob nullglob
    fi
    echo "OK: trash emptied"
}

# ---------------------------------------------------------------------------
# File backup / restore (tar) for website document roots and Node.js apps -
# needed because 'panel' cannot read files owned by www-data/nodeapps.
# ---------------------------------------------------------------------------
op_backup_tar_website() {
    local domain="$1" outfile="$2"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_path_within "$outfile" "$BACKUP_BASE" >/dev/null
    local src="${WWW_BASE}/${domain}"
    [[ -d "$src" ]] || fail "Direktori website tidak ditemukan: $src"
    mkdir -p "$(dirname "$outfile")"
    # Excludes Recycle Bin contents (see op_files_delete) - without this,
    # backups grow forever (trash never auto-expires) AND restoring a
    # backup would resurrect files the admin deliberately deleted before
    # that backup was taken.
    tar -czf "$outfile" --exclude="${domain}/.trash" -C "$WWW_BASE" "$domain"
    chown panel:panel "$outfile"
    chmod 640 "$outfile"
    echo "OK: backup ${domain} -> ${outfile}"
}

op_backup_tar_nodeapp() {
    local app="$1" outfile="$2"
    require_match "$app" "$RE_APPNAME" "appname"
    require_path_within "$outfile" "$BACKUP_BASE" >/dev/null
    local src="${NODEAPPS_BASE}/${app}"
    [[ -d "$src" ]] || fail "Direktori aplikasi tidak ditemukan: $src"
    mkdir -p "$(dirname "$outfile")"
    tar -czf "$outfile" --exclude="${app}/.trash" -C "$NODEAPPS_BASE" "$app"
    chown panel:panel "$outfile"
    chmod 640 "$outfile"
    echo "OK: backup ${app} -> ${outfile}"
}

op_restore_tar_website() {
    local infile="$1" domain="$2"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_path_within "$infile" "$BACKUP_BASE" >/dev/null
    [[ -f "$infile" ]] || fail "File backup tidak ditemukan: $infile"
    tar -xzf "$infile" -C "$WWW_BASE"
    chown -R www-data:www-data "${WWW_BASE}/${domain}"
    echo "OK: restore ${domain} <- ${infile}"
}

op_restore_tar_nodeapp() {
    local infile="$1" app="$2"
    require_match "$app" "$RE_APPNAME" "appname"
    require_path_within "$infile" "$BACKUP_BASE" >/dev/null
    [[ -f "$infile" ]] || fail "File backup tidak ditemukan: $infile"
    tar -xzf "$infile" -C "$NODEAPPS_BASE"
    chown -R nodeapps:nodeapps "${NODEAPPS_BASE}/${app}"
    echo "OK: restore ${app} <- ${infile}"
}

# ---------------------------------------------------------------------------
# Cron job files - written as discrete /etc/cron.d/ files (one per job id),
# never by editing a shared crontab in place.
# ---------------------------------------------------------------------------
RE_CRONID='^panel-[0-9]+$'

op_cron_write() {
    local jobid="$1"
    require_match "$jobid" "$RE_CRONID" "cron job id"
    local target="/etc/cron.d/${jobid}"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    [[ -s "$tmp" ]] || { rm -f "$tmp"; fail "Konten cron kosong"; }
    mv "$tmp" "$target"
    chown root:root "$target"
    chmod 644 "$target"
    echo "OK: cron ${jobid} written"
}

op_cron_delete() {
    local jobid="$1"
    require_match "$jobid" "$RE_CRONID" "cron job id"
    rm -f "/etc/cron.d/${jobid}"
    echo "OK: cron ${jobid} removed"
}

# ---------------------------------------------------------------------------
# Log tail - whitelisted log keys only, mapped internally to fixed paths.
# ---------------------------------------------------------------------------
op_log_tail() {
    local logkey="$1" lines="${2:-200}"
    require_match "$lines" "$RE_LINES" "lines"
    [[ "$lines" -le 2000 ]] || lines=2000

    local path=""
    case "$logkey" in
        nginx-access:*)
            local d="${logkey#nginx-access:}"
            require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-access.log"
            ;;
        nginx-error:*)
            local d="${logkey#nginx-error:}"
            require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-error.log"
            ;;
        phpfpm-error:*)
            local v="${logkey#phpfpm-error:}"
            case "$v" in
                7.4|8.0|8.1|8.2|8.3|8.4) ;;
                *) fail "Versi PHP tidak diizinkan: $v" ;;
            esac
            path="/var/log/php${v}-fpm.log"
            ;;
        deployment)
            path="/var/log/yuuka-installer/deployment.log"
            ;;
        self-update)
            path="$SELF_UPDATE_LOG"
            ;;
        *) fail "Log key tidak dikenal: $logkey" ;;
    esac

    [[ -f "$path" ]] || { echo ""; return 0; }
    tail -n "$lines" "$path"
}

op_log_clear() {
    local logkey="$1"
    # Reuse the same whitelist/path resolution as op_log_tail by calling it
    # with 0 lines is not safe (still reads); resolve path again explicitly.
    local path=""
    case "$logkey" in
        nginx-access:*)
            local d="${logkey#nginx-access:}"; require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-access.log" ;;
        nginx-error:*)
            local d="${logkey#nginx-error:}"; require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-error.log" ;;
        *) fail "Log key tidak dapat dikosongkan: $logkey" ;;
    esac
    [[ -f "$path" ]] && : > "$path"
    echo "OK: cleared $logkey"
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
SUBCOMMAND="${1:-}"
[[ -n "$SUBCOMMAND" ]] || { echo "Usage: panel-exec.sh <subcommand> [args...]" >&2; exit 2; }
shift || true

case "$SUBCOMMAND" in
    nginx-test)            op_nginx_test ;;
    nginx-reload)          op_nginx_reload ;;
    nginx-write-config)    op_nginx_write_config "$@" ;;
    nginx-enable)          op_nginx_enable "$@" ;;
    nginx-disable)         op_nginx_disable "$@" ;;
    nginx-delete)          op_nginx_delete "$@" ;;
    pm2-deploy)            op_pm2_deploy "$@" ;;
    pm2-start)             op_pm2_start "$@" ;;
    pm2-stop)              op_pm2_stop "$@" ;;
    pm2-restart)           op_pm2_restart "$@" ;;
    pm2-reload)            op_pm2_reload "$@" ;;
    pm2-delete)            op_pm2_delete "$@" ;;
    pm2-jlist)             op_pm2_jlist ;;
    pm2-describe)          op_pm2_describe "$@" ;;
    pm2-logs)              op_pm2_logs "$@" ;;
    pm2-flush)             op_pm2_flush "$@" ;;
    pm2-save)              op_pm2_save ;;
    certbot-issue)         op_certbot_issue "$@" ;;
    certbot-remove)        op_certbot_remove "$@" ;;
    service-status)        op_service_status "$@" ;;
    service-restart)       op_service_restart "$@" ;;
    installer-version-info)       op_installer_version_info ;;
    installer-check-update)       op_installer_check_update ;;
    installer-self-update)        op_installer_self_update ;;
    installer-self-update-status) op_installer_self_update_status ;;
    mysqldump-db)          op_mysqldump_db "$@" ;;
    mysql-restore-db)      op_mysql_restore_db "$@" ;;
    cloudflared-status)    op_cloudflared_status ;;
    cloudflared-restart)   op_cloudflared_restart ;;
    cloudflared-stop)      op_cloudflared_stop ;;
    cloudflared-start)     op_cloudflared_start ;;
    cloudflared-version)   op_cloudflared_version ;;
    disk-usage)            op_disk_usage ;;
    fs-mkdir-website)      op_fs_mkdir_website "$@" ;;
    fs-remove-website)     op_fs_remove_website "$@" ;;
    fs-remove-nodeapp)     op_fs_remove_nodeapp "$@" ;;
    port-check)            op_port_check "$@" ;;
    files-list)            op_files_list "$@" ;;
    files-read)            op_files_read "$@" ;;
    files-write)           op_files_write "$@" ;;
    files-mkdir)           op_files_mkdir "$@" ;;
    files-delete)          op_files_delete "$@" ;;
    files-rename)          op_files_rename "$@" ;;
    files-extract-zip)     op_files_extract_zip "$@" ;;
    files-copy)            op_files_copy "$@" ;;
    files-move)            op_files_move "$@" ;;
    files-chmod)           op_files_chmod "$@" ;;
    files-search)          op_files_search "$@" ;;
    files-trash-list)      op_files_trash_list "$@" ;;
    files-trash-restore)   op_files_trash_restore "$@" ;;
    files-trash-delete)    op_files_trash_delete "$@" ;;
    files-trash-empty)     op_files_trash_empty "$@" ;;
    backup-tar-website)    op_backup_tar_website "$@" ;;
    backup-tar-nodeapp)    op_backup_tar_nodeapp "$@" ;;
    restore-tar-website)   op_restore_tar_website "$@" ;;
    restore-tar-nodeapp)   op_restore_tar_nodeapp "$@" ;;
    cron-write)            op_cron_write "$@" ;;
    cron-delete)           op_cron_delete "$@" ;;
    log-tail)              op_log_tail "$@" ;;
    log-clear)             op_log_clear "$@" ;;
    *)
        echo "ERROR: subcommand tidak dikenal: ${SUBCOMMAND}" >&2
        audit "$SUBCOMMAND" "rejected:unknown-subcommand"
        exit 2
        ;;
esac

audit "$SUBCOMMAND" "ok"
