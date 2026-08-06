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
NGINX_SNIPPETS="/etc/nginx/snippets"
WWW_BASE="/var/www"
NODEAPPS_BASE="/home/nodeapps/apps"
NODEAPPS_HOME="/home/nodeapps"
# Live log for app "foo" is always ${PM2_LOG_DIR}/foo.log (see
# nodejs_build_ecosystem_config() - out_file AND error_file both point
# here, merging stdout+stderr into one file). Archived per-run files sit
# right next to it as ${PM2_LOG_DIR}/foo<17-digit-timestamp>.log, written
# by rotate_pm2_log() below.
PM2_LOG_DIR="/home/nodeapps/.pm2/logs"
BACKUP_BASE="/opt/server-panel/storage/backups"
ACME_WEBROOT="/var/www/_letsencrypt"
INSTALLER_DIR="/opt/yuuka-installer"
SELF_UPDATE_LOG="/opt/server-panel/storage/logs/self-update.log"
PANEL_SSL_APPLY_LOG="/opt/server-panel/storage/logs/ssl-apply.log"
# Mirrors modules/panel.sh's PANEL_ROOT/PANEL_POOL_SOCK exactly - this
# script is standalone (not sourced from modules/panel.sh), so these are
# duplicated constants rather than a shared include, matching how every
# other path in this file is already hardcoded rather than sourced.
PANEL_ROOT="/opt/server-panel"
PANEL_POOL_SOCK="/run/php/panel.sock"
BASICAUTH_HTPASSWD="/etc/nginx/panel.htpasswd"
# Plugin system - see op_plugin_exec() below for the trust model (an
# enabled plugin's OWN scripts run with full root privilege, by explicit
# operator choice over a sandboxed alternative). Never inside PANEL_ROOT
# itself (deploy's rsync target) - a plugin surviving `sudo bash
# update.sh` re-deploys is unrelated to that directory's own lifecycle.
PLUGIN_DIR="/opt/server-panel-plugins"

# Mirrors yp's own panel_vhost_file() - the panel vhost is the only file
# generated with this naming pattern (modules/panel.sh), so a glob is
# sufficient without needing to know PANEL_DOMAIN in this standalone script.
panel_vhost_file() {
    find "$NGINX_AVAILABLE" -maxdepth 1 -name 'panel-*.conf' 2>/dev/null | head -1
}

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
# HTTPS only, deliberately - no git@host:path SSH form, which would need
# a deploy key provisioned for the www-data user (a whole separate
# credential-management surface not built here). A private repo is still
# reachable by embedding a token in the URL itself
# (https://user:TOKEN@host/...), which git supports natively.
# {1,200}, not a rounder/larger number - some regex engines' bounded-
# repetition (RE_DUP_MAX) silently fails to match ANYTHING once the
# upper bound crosses an implementation-defined ceiling (confirmed
# empirically: {1,256} already breaks in one environment tested against
# this exact codebase, {1,200} does not) - 200 is comfortably clear of
# that while still far more than any real git URL needs.
RE_GIT_URL='^https://[a-zA-Z0-9._~:/?#@!$&*+,;=%-]{1,200}$'
RE_GIT_BRANCH='^[a-zA-Z0-9._/-]{1,200}$'
RE_EMAIL='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
RE_DBNAME='^[a-zA-Z0-9_]{1,64}$'
RE_LINES='^[0-9]{1,4}$'
RE_BYTE_OFFSET='^[0-9]{1,19}$'
RE_PORT='^[0-9]{1,5}$'
RE_PLUGIN_SLUG='^[a-z0-9][a-z0-9_-]{0,63}$'
RE_PLUGIN_SCRIPT='^[a-zA-Z0-9_-]{1,64}$'
RE_NODE_VERSION='^[0-9]{1,3}$'
# Mirrors Validator::buildCommand()/NodeService's own PHP-side regex - no
# shell metacharacters (;&|`$(){}<>) at all, so even though as_nodeapps
# below interpolates this into a `bash -lc "..."` string, it cannot be
# used to chain or inject additional commands.
RE_BUILD_COMMAND='^[a-zA-Z0-9_./ -]{1,255}$'
# Same whitelist op_service_status already enforces via its own case
# statement - factored into a regex here for op_service_restart, which is
# a mutating action and deserves the exact same explicit require_match
# pattern used everywhere else in this file.
RE_RESTARTABLE_SERVICE='^(nginx|mariadb|cloudflared|php7\.4-fpm|php8\.0-fpm|php8\.1-fpm|php8\.2-fpm|php8\.3-fpm|php8\.4-fpm)$'
RE_ENABLE_DISABLE='^(enable|disable)$'
RE_BASICAUTH_USERNAME='^[a-zA-Z0-9_.-]{3,64}$'
# PHP's password_hash($pw, PASSWORD_BCRYPT) format: $2y$<cost>$<53 chars> -
# validated here too (defense in depth) even though PHP already only ever
# sends its own freshly-computed hash, never a user-supplied string.
RE_BCRYPT_HASH='^\$2[abxy]\$[0-9]{2}\$[A-Za-z0-9./]{53}$'
RE_SECURITY_ENTRANCE_PATH='^[a-zA-Z0-9_-]{3,64}$'

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

# `limit_req_zone` is only valid at nginx's http{} context, never inside a
# single server{} block - every rate-limited site's zone is declared
# together in this ONE shared file instead, auto-included at http level
# by Debian/Ubuntu's stock nginx.conf (`include /etc/nginx/conf.d/*.conf;`)
# with no further wiring needed. Full content always comes from
# NginxService, fully regenerated from the current DB state on every call
# - this just validates+applies it (same test-then-rollback safety net as
# op_nginx_write_config above).
op_nginx_write_ratelimit_zones() {
    local target="/etc/nginx/conf.d/panel-rate-limits.conf"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    [[ -s "$tmp" ]] || { rm -f "$tmp"; fail "Konten kosong"; }

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
        fail "nginx -t gagal, rate limit dibatalkan: ${err}"
    fi
    rm -f "/tmp/nginx-test-err.$$" "$previous_backup" 2>/dev/null || true
    systemctl reload nginx
    echo "OK: rate limit zones diterapkan"
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

# ---------------------------------------------------------------------------
# Panel BasicAuth - an additional Nginx-level login prompt in front of the
# panel's own vhost (Settings > General). Only ever writes a small snippet
# file (included at server-block level by module_panel_nginx_vhost, same
# self-healing pattern as pma_include/terminal_include) plus the htpasswd
# file - never the vhost itself, so a bad toggle can't corrupt the whole
# panel config. PHP always sends an already-computed bcrypt hash (never a
# raw password), matching how panel_users passwords are already hashed
# before ever reaching a privileged layer.
# ---------------------------------------------------------------------------
op_panel_basicauth_set() {
    local mode="$1"
    require_match "$mode" "$RE_ENABLE_DISABLE" "mode"
    local snippet="${NGINX_SNIPPETS}/includes/panel-basicauth.conf"
    mkdir -p "$(dirname "$snippet")"

    # module_panel_nginx_vhost (modules/panel.sh) only wires this snippet's
    # 'include' line into the panel vhost the NEXT time the vhost itself is
    # regenerated (install/repair) - it never runs from here. Without also
    # syncing that line in THIS function, enabling silently has no effect
    # until some unrelated 'yp repair panel' happens to pick it up, and
    # disabling leaves a dangling include pointing at a now-deleted file,
    # which breaks 'nginx -t' (and therefore every future reload) until
    # someone notices and runs a repair manually.
    local vhost
    vhost=$(panel_vhost_file)
    local include_line="include ${snippet};"

    local prev_snippet="" prev_htpasswd="" prev_vhost=""
    if [[ -f "$snippet" ]]; then
        prev_snippet=$(mktemp)
        cp -a "$snippet" "$prev_snippet"
    fi
    if [[ -f "$BASICAUTH_HTPASSWD" ]]; then
        prev_htpasswd=$(mktemp)
        cp -a "$BASICAUTH_HTPASSWD" "$prev_htpasswd"
    fi
    if [[ -n "$vhost" && -f "$vhost" ]]; then
        prev_vhost=$(mktemp)
        cp -a "$vhost" "$prev_vhost"
    fi

    if [[ "$mode" == "disable" ]]; then
        rm -f "$snippet" "$BASICAUTH_HTPASSWD"
        if [[ -n "$vhost" && -f "$vhost" ]]; then
            grep -vF "$include_line" "$vhost" > "${vhost}.tmp" && mv "${vhost}.tmp" "$vhost"
        fi
    else
        local username="$2" hash="$3"
        require_match "$username" "$RE_BASICAUTH_USERNAME" "username"
        require_match "$hash" "$RE_BCRYPT_HASH" "hash"
        printf '%s:%s\n' "$username" "$hash" > "$BASICAUTH_HTPASSWD"
        # ngx_http_auth_basic_module reads this file on every request (not
        # just once at config-load time, precisely so credentials can be
        # rotated without a reload) - it's the WORKER process (user
        # www-data, not root) that needs read access, not just the master.
        chown root:www-data "$BASICAUTH_HTPASSWD"
        chmod 640 "$BASICAUTH_HTPASSWD"
        cat > "$snippet" <<EOF
auth_basic "Restricted";
auth_basic_user_file ${BASICAUTH_HTPASSWD};
EOF
        chown root:root "$snippet"
        chmod 644 "$snippet"
        if [[ -n "$vhost" && -f "$vhost" ]] && ! grep -qF "$include_line" "$vhost"; then
            # Deliberately NOT "\$i\\${include_line}" - a literal backslash
            # immediately before a bash ${...} expansion (i.e. "\\${var}")
            # suppresses the expansion entirely in this shell, inserting the
            # unexpanded text "${include_line}" into the file instead of its
            # value. GNU sed's one-line 'i' extension needs no backslash
            # continuation at all, which sidesteps the problem.
            sed -i "\$i${include_line}" "$vhost"
        fi
    fi

    if ! nginx -t 2>/tmp/nginx-test-err.$$; then
        if [[ -n "$prev_snippet" ]]; then mv "$prev_snippet" "$snippet"; else rm -f "$snippet"; fi
        if [[ -n "$prev_htpasswd" ]]; then mv "$prev_htpasswd" "$BASICAUTH_HTPASSWD"; else rm -f "$BASICAUTH_HTPASSWD"; fi
        if [[ -n "$prev_vhost" ]]; then mv "$prev_vhost" "$vhost"; fi
        local err
        err=$(cat /tmp/nginx-test-err.$$ 2>/dev/null || true)
        rm -f "/tmp/nginx-test-err.$$"
        fail "nginx -t gagal, BasicAuth dibatalkan: ${err}"
    fi
    rm -f "/tmp/nginx-test-err.$$" "$prev_snippet" "$prev_htpasswd" "$prev_vhost" 2>/dev/null || true
    systemctl reload nginx
    echo "OK: basicauth ${mode}"
}

# ---------------------------------------------------------------------------
# Panel Security Entrance - moves the panel login form off the guessable
# /login path. `internal;` (identical pattern to terminal_auth.php's
# location block) makes /login return 404 for any DIRECT external
# request - it's only reachable via the nginx-internal rewrite from the
# secret path, which never touches the browser's address bar as a
# separate hop. Login itself (username+password+RBAC) is completely
# unchanged; this only decides whether a request ever reaches that logic.
#
# BOTH /login and /login.php get their own `internal;` block, not just
# one - the panel vhost's own extension-less URL support (module_panel_
# nginx_vhost in modules/panel.sh) does `try_files $uri $uri.php ...`,
# which is ITSELF an internal redirect. Without a matching `internal;`
# block for the exact /login path too, a direct external request for
# plain "/login" would satisfy that try_files fallback to login.php and
# nginx would treat the whole chain as internal-origin, completely
# bypassing this feature's entire purpose - only blocking the .php form
# left the clean-URL form wide open.
#
# The one real risk here is a self-inflicted lockout (wrong/forgotten
# path = nobody can reach /login at all, including to undo this) -
# that's what `yp security-entrance` (SSH, bypasses the panel and this
# script entirely) exists for.
# ---------------------------------------------------------------------------
op_panel_security_entrance_set() {
    local mode="$1"
    require_match "$mode" "$RE_ENABLE_DISABLE" "mode"
    local snippet="${NGINX_SNIPPETS}/includes/security-entrance.conf"
    mkdir -p "$(dirname "$snippet")"

    # Same reasoning as op_panel_basicauth_set above: the panel vhost's
    # 'include' line for this snippet is only synced by module_panel_nginx_vhost
    # (modules/panel.sh), which doesn't run from here - so this function has
    # to keep it in sync itself, or enabling silently does nothing and
    # disabling leaves a dangling include that breaks 'nginx -t'. That
    # second failure mode is especially bad here since 'off' is the
    # documented lockout-recovery escape hatch (see yp's cmd_security_entrance).
    local vhost
    vhost=$(panel_vhost_file)
    local include_line="include ${snippet};"

    local prev_snippet="" prev_vhost=""
    if [[ -f "$snippet" ]]; then
        prev_snippet=$(mktemp)
        cp -a "$snippet" "$prev_snippet"
    fi
    if [[ -n "$vhost" && -f "$vhost" ]]; then
        prev_vhost=$(mktemp)
        cp -a "$vhost" "$prev_vhost"
    fi

    if [[ "$mode" == "disable" ]]; then
        rm -f "$snippet"
        if [[ -n "$vhost" && -f "$vhost" ]]; then
            grep -vF "$include_line" "$vhost" > "${vhost}.tmp" && mv "${vhost}.tmp" "$vhost"
        fi
    else
        local path="$2"
        require_match "$path" "$RE_SECURITY_ENTRANCE_PATH" "path"
        cat > "$snippet" <<EOF
location = /login.php {
    internal;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PANEL_POOL_SOCK};
    fastcgi_param SCRIPT_FILENAME ${PANEL_ROOT}/public/login.php;
}
location = /login {
    internal;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PANEL_POOL_SOCK};
    fastcgi_param SCRIPT_FILENAME ${PANEL_ROOT}/public/login.php;
}
location = /${path} {
    rewrite ^ /login last;
}
EOF
        chown root:root "$snippet"
        chmod 644 "$snippet"
        if [[ -n "$vhost" && -f "$vhost" ]] && ! grep -qF "$include_line" "$vhost"; then
            # See op_panel_basicauth_set's comment on this exact line shape -
            # "\\${include_line}" would silently insert the LITERAL text
            # "${include_line}" instead of its value.
            sed -i "\$i${include_line}" "$vhost"
        fi
    fi

    if ! nginx -t 2>/tmp/nginx-test-err.$$; then
        if [[ -n "$prev_snippet" ]]; then mv "$prev_snippet" "$snippet"; else rm -f "$snippet"; fi
        if [[ -n "$prev_vhost" ]]; then mv "$prev_vhost" "$vhost"; fi
        local err
        err=$(cat /tmp/nginx-test-err.$$ 2>/dev/null || true)
        rm -f "/tmp/nginx-test-err.$$"
        fail "nginx -t gagal, Security Entrance dibatalkan: ${err}"
    fi
    rm -f "/tmp/nginx-test-err.$$" "$prev_snippet" "$prev_vhost" 2>/dev/null || true
    systemctl reload nginx
    echo "OK: security-entrance ${mode}"
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

# Gives every PM2 (re)start its own log file ("<app><17-digit
# YYYYMMDDHHMMSSmmm timestamp>.log") instead of one file that just grows
# forever - called right before every op that (re)starts an app's
# process. PM2 keeps an already-open, append-mode file descriptor on the
# LIVE path (${PM2_LOG_DIR}/${app}.log) for as long as the process is up.
# Renaming that path away would NOT give it a fresh file - file
# descriptors follow the inode, not the path, so PM2 would keep silently
# writing into the (now differently-named) archived file forever.
# Copying the content out and then truncating the SAME inode in place
# sidesteps that entirely: PM2's existing fd stays perfectly valid and
# next write() just lands at offset 0 again - no signal/reopen needed on
# PM2's side (relying on that, e.g. via `pm2 reloadLogs`, would also
# affect every OTHER app sharing this same PM2 daemon, not just this one).
rotate_pm2_log() {
    local app="$1"
    local live_log="${PM2_LOG_DIR}/${app}.log"
    [[ -s "$live_log" ]] || return 0
    local ts
    ts=$(date +%Y%m%d%H%M%S%3N)
    cp -p "$live_log" "${PM2_LOG_DIR}/${app}${ts}.log" 2>/dev/null || true
    : > "$live_log"
}

op_pm2_deploy() {
    local app="$1" node_version="${2:-}" build_command="${3:-}"
    require_match "$app" "$RE_APPNAME" "appname"
    local app_dir="${NODEAPPS_BASE}/${app}"
    require_path_within "$app_dir" "$NODEAPPS_BASE" >/dev/null

    mkdir -p "$app_dir"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    [[ -s "$tmp" ]] || { rm -f "$tmp"; fail "Ecosystem config kosong"; }

    # .cjs (not .js) is deliberate: the content we write is always
    # CommonJS (`module.exports = {...}`), but if the app's OWN
    # package.json declares "type": "module", Node treats any plain .js
    # file as an ES module and PM2 crashes immediately with "ReferenceError:
    # module is not defined in ES module scope" before ever reaching the
    # app's actual script. .cjs is exempt from that package.json-driven
    # detection no matter the app's own module type, so this always loads
    # as CommonJS. Remove any stale pre-.cjs-fix file from an older deploy
    # so it can't linger and confuse a manual `pm2 start`.
    rm -f "${app_dir}/ecosystem.config.js"
    mv "$tmp" "${app_dir}/ecosystem.config.cjs"
    chown -R nodeapps:nodeapps "$app_dir"
    chmod 750 "$app_dir"
    chmod 640 "${app_dir}/ecosystem.config.cjs"
    fm_reapply_terminal_acl "$app_dir"

    # First-ever deploy runs against a directory that's still empty by
    # design - real project files are meant to be uploaded/git-cloned
    # AFTER the app is registered (see NodeService::createApp()'s
    # comment) - so `pm2 start` against a genuinely missing script file
    # used to hard-fail app creation entirely (RuntimeException thrown,
    # DB row never inserted, operator stuck before they could even upload
    # code). Scaffold a tiny placeholder HTTP server at the configured
    # script path instead, ONLY when nothing is there yet, so the app
    # comes up successfully and the operator can swap in real code +
    # redeploy whenever it's ready. Never touches a file that already
    # exists. Detects ESM vs CommonJS from the app's own package.json (if
    # any) so the placeholder itself doesn't hit the same "type": "module"
    # trap that .cjs above just fixed for the ecosystem file.
    local script_rel script_path
    script_rel=$(as_nodeapps "node -e 'const c=require(process.argv[1]); process.stdout.write(String(c.apps[0].script))' '${app_dir}/ecosystem.config.cjs'" 2>/dev/null) || script_rel=""
    if [[ -n "$script_rel" ]]; then
        script_path=$(require_path_within "${app_dir}/${script_rel}" "$app_dir")
        if [[ ! -e "$script_path" ]]; then
            mkdir -p "$(dirname "$script_path")"
            local is_esm=0
            if [[ -f "${app_dir}/package.json" ]] && grep -q '"type"[[:space:]]*:[[:space:]]*"module"' "${app_dir}/package.json" 2>/dev/null; then
                is_esm=1
            fi
            if [[ "$is_esm" == "1" ]]; then
                cat > "$script_path" <<'TEMPLATE_EOF'
import http from 'node:http';
const port = process.env.PORT || 3000;
http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('Yuuka Panel: template default aktif. Ganti file ini dengan kode aplikasi Anda lalu redeploy.\n');
}).listen(port, () => console.log(`[template] listening on port ${port}`));
TEMPLATE_EOF
            else
                cat > "$script_path" <<'TEMPLATE_EOF'
const http = require('node:http');
const port = process.env.PORT || 3000;
http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('Yuuka Panel: template default aktif. Ganti file ini dengan kode aplikasi Anda lalu redeploy.\n');
}).listen(port, () => console.log(`[template] listening on port ${port}`));
TEMPLATE_EOF
            fi
            chown -R nodeapps:nodeapps "$app_dir"
        fi
    fi

    # "nvm use <version>" only changes PATH for THIS shell invocation, but
    # that's enough: PM2 resolves its default 'node' interpreter to an
    # absolute path via PATH at the moment `pm2 start`/`pm2 restart` is
    # run, then keeps using that resolved path for the process from then
    # on - the standard documented way to pin a per-app Node version under
    # a single shared PM2 daemon. 'unknown' (see NodeService::importUnmanaged)
    # or empty means "don't touch PATH, use whatever nvm's own default is".
    local nvm_use=""
    if [[ -n "$node_version" && "$node_version" != "unknown" ]]; then
        require_match "$node_version" "$RE_NODE_VERSION" "node_version"
        nvm_use="nvm use ${node_version} >/dev/null 2>&1 || true; "
    fi

    if [[ -n "$build_command" ]]; then
        require_match "$build_command" "$RE_BUILD_COMMAND" "build command"
        as_nodeapps "${nvm_use}cd '${app_dir}' && ${build_command}" \
            || fail "Build command gagal: ${build_command}"
    fi

    # 'pm2' itself is only installed as a global npm package under whichever
    # Node version was active at setup time (modules/nodejs.sh's `npm
    # install -g pm2`) - nvm scopes global installs PER version, so once
    # 'nvm use <version>' above swaps PATH to the app's own chosen version,
    # 'pm2' silently disappears from PATH for any app pinned to a version
    # other than the one it was installed under ("pm2: command not found",
    # only ever hit on deploy since this is the only op that calls nvm use
    # at all). Resolve pm2's absolute path FIRST, while the default nvm
    # version (where it actually lives) is still active, then invoke that
    # absolute path AFTER switching - 'env node' inside pm2's own shebang
    # still resolves to the just-switched target version, which is what
    # actually determines the app's runtime interpreter.
    rotate_pm2_log "$app"
    # 'pm2 start ecosystem.config.js --update-env' on an app that's ALREADY
    # registered does not reliably re-apply structural launch options like
    # out_file/error_file - only env vars are guaranteed to update that
    # way. PM2 keeps using whatever log paths were set the very FIRST time
    # this app name was started, so a deploy predating out_file/error_file
    # being pointed at PM2_LOG_DIR/${app}.log (see
    # nodejs_build_ecosystem_config()) kept silently writing to PM2's own
    # default <name>-out-N.log/<name>-error-N.log forever - confirmed live
    # (every existing app's log dir still had exactly that split-file
    # pattern, panel's combined Logs tab showing "Belum ada output log."
    # for all of them). Deleting first forces every deploy to register the
    # process fresh, so out_file/error_file (and anything else PM2 only
    # honors at creation time) are always current. Harmless no-op (the
    # `|| true`) on a genuinely first-ever deploy, where there's nothing to
    # delete yet.
    as_nodeapps "pm2 delete '${app}' >/dev/null 2>&1 || true"
    as_nodeapps "PM2_BIN=\$(command -v pm2); ${nvm_use}\"\${PM2_BIN:?pm2 tidak ditemukan di PATH}\" start '${app_dir}/ecosystem.config.cjs' --update-env"
    as_nodeapps "pm2 save"
    echo "OK: ${app} deployed via PM2"
}

op_pm2_start() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    rotate_pm2_log "$app"
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
    rotate_pm2_log "$app"
    as_nodeapps "pm2 restart '${app}'"
}

op_pm2_reload() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    rotate_pm2_log "$app"
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

# Bulk "restart all Node.js apps" for Sistem > Status & Restart Layanan -
# distinct from op_pm2_restart's single-app form (used from each app's own
# page). Rotates every app's live log first, same as the single-app path,
# so a bulk restart doesn't silently mix multiple runs into one log file.
op_pm2_restart_all() {
    local app
    while IFS= read -r app; do
        [[ -n "$app" ]] || continue
        rotate_pm2_log "$app"
    done < <(as_nodeapps "pm2 jlist" | jq -r '.[].name' 2>/dev/null)
    as_nodeapps "pm2 restart all"
}

op_pm2_describe() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 describe '${app}'"
}

# Reads the raw live log FILE directly instead of `pm2 logs` (the CLI
# command decorates every line with "<pm_id>|<app-name> |", meant for a
# human watching several apps interleaved in one terminal - there's only
# ever one app on screen here, so that prefix was pure noise the panel
# had no way to strip cleanly).
op_pm2_logs() {
    local app="$1" lines="${2:-100}"
    require_match "$app" "$RE_APPNAME" "appname"
    require_match "$lines" "$RE_LINES" "lines"
    [[ "$lines" -le 1000 ]] || lines=1000
    local live_log="${PM2_LOG_DIR}/${app}.log"
    [[ -f "$live_log" ]] && tail -n "$lines" "$live_log"
    return 0
}

# Current live log file size in bytes - lets the panel start a real-time
# stream exactly where the initial (separately-fetched, N-line) view left
# off, without re-sending or re-requesting any content just to find that
# starting point.
op_pm2_logs_size() {
    local app="$1"
    require_match "$app" "$RE_APPNAME" "appname"
    local live_log="${PM2_LOG_DIR}/${app}.log"
    if [[ -f "$live_log" ]]; then
        stat -c%s "$live_log"
    else
        echo "0"
    fi
}

# Incremental read for the real-time log viewer (nodejs_logs_stream.php) -
# avoids re-sending the whole tail on every poll tick. First line of
# output is always the new byte offset to pass back in next time; every
# byte after that first newline is the raw new content (may be nothing).
op_pm2_logs_tail() {
    local app="$1" offset="${2:-0}"
    require_match "$app" "$RE_APPNAME" "appname"
    require_match "$offset" "$RE_BYTE_OFFSET" "offset"
    local live_log="${PM2_LOG_DIR}/${app}.log"
    if [[ ! -f "$live_log" ]]; then
        echo "0"
        return 0
    fi
    local size
    size=$(stat -c%s "$live_log")
    # File is smaller than the offset we were tracking - it was rotated
    # (a start/restart happened) since the last poll, so that offset no
    # longer means anything against this now-different file. Start over.
    if (( offset > size )); then
        offset=0
    fi
    echo "$size"
    tail -c "+$((offset + 1))" "$live_log"
}

# Archived run files for $app, newest first - filename alone doubles as
# the human-readable "run started at" label since it IS the timestamp.
op_pm2_logs_list() {
    local app="$1"
    require_match "$app" "$RE_APPNAME" "appname"
    mkdir -p "$PM2_LOG_DIR"
    find "$PM2_LOG_DIR" -maxdepth 1 -type f \
        -name "${app}[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].log" \
        -printf '%f\n' 2>/dev/null | sort -r
}

op_pm2_logs_read_archive() {
    local app="$1" file="$2" lines="${3:-1000}"
    require_match "$app" "$RE_APPNAME" "appname"
    require_match "$lines" "$RE_LINES" "lines"
    [[ "$lines" -le 1000 ]] || lines=1000
    # Tied to THIS specific app (not just "looks like some archive name")
    # so one app's log history can't be used to read another's.
    require_match "$file" "^${app}[0-9]{17}\.log\$" "log file"
    local path
    path=$(require_path_within "${PM2_LOG_DIR}/${file}" "$PM2_LOG_DIR")
    [[ -f "$path" ]] || fail "File log tidak ditemukan"
    tail -n "$lines" "$path"
}

op_pm2_flush() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 flush '${app}'"
}

op_pm2_reset() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 reset '${app}'"
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

# Issues SSL specifically for the PANEL'S OWN domain (Settings > SSL
# Panel), not a generic website domain - see SSLService::issueForPanelDomain().
# Deliberately takes no domain argument from the caller: derives it from
# the already-live panel vhost on disk, so this can never be pointed at an
# arbitrary domain by a buggy/compromised PHP layer.
#
# certbot itself runs synchronously (fast, no service restart involved -
# safe to wait for and report errors from). Applying it, though
# ('yp repair panel' - regenerates the vhost's 443 block and syncs .env's
# SESSION_SECURE_COOKIE/APP_URL), restarts the panel's OWN PHP-FPM pool.
# Running that synchronously here would kill the very worker process
# handling THIS request before it can ever send a response - confirmed
# live as a 502 Bad Gateway on the settings.php POST that triggered this.
# Same escape hatch op_installer_self_update() already uses for the exact
# same class of problem: schedule it as an independent transient unit and
# return immediately - the cert is already safely on disk by that point,
# only the vhost/.env sync is deferred by a few seconds.
op_panel_ssl_issue() {
    local email="$1"
    require_match "$email" "$RE_EMAIL" "email"

    local vhost domain
    vhost=$(find /etc/nginx/sites-available -maxdepth 1 -name 'panel-*.conf' 2>/dev/null | head -1)
    [[ -n "$vhost" ]] || fail "Vhost panel tidak ditemukan"
    domain=$(grep -h 'server_name' "$vhost" | head -1 | awk '{print $2}' | tr -d ';')
    require_match "$domain" "$RE_DOMAIN" "domain (dari vhost panel)"

    certbot certonly --webroot -w "$ACME_WEBROOT" -d "$domain" \
        --non-interactive --agree-tos -m "$email" --no-eff-email \
        || fail "Penerbitan sertifikat gagal (pastikan DNS ${domain} sudah mengarah ke server ini)"

    command -v yp >/dev/null 2>&1 || fail "Sertifikat terbit untuk ${domain} tapi 'yp' tidak ditemukan di PATH - jalankan manual: sudo yp repair panel"

    mkdir -p "$(dirname "$PANEL_SSL_APPLY_LOG")"
    systemd-run --unit=yuuka-panel-ssl-apply --collect \
        --description="Yuuka Panel: terapkan SSL domain panel" \
        -- /bin/bash -c "{ echo \"=== \$(date -Iseconds) mulai ===\"; yp repair panel; echo \"=== \$(date -Iseconds) selesai (exit=\$?) ===\"; } >>'${PANEL_SSL_APPLY_LOG}' 2>&1" \
        < /dev/null \
        || fail "Sertifikat terbit untuk ${domain} tapi gagal menjadwalkan penerapannya - jalankan manual: sudo yp repair panel"

    echo "OK: sertifikat terbit untuk ${domain}, menerapkan konfigurasi di background (PHP-FPM panel akan restart sebentar - refresh halaman dalam beberapa detik)"
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

# Clones a git repo directly into the website's root dir (NOT its own
# subfolder) - the panel's Nginx template always serves from
# {dir}/public (see NginxService::createWebsite), so this expects the
# repo to already contain its own public/ folder at the top level, the
# same convention Laravel/Symfony/Slim/CodeIgniter 4 etc. all use. A repo
# without one still clones fine, it just won't serve anything useful
# until one exists (mkdir -p below only guarantees the directory itself
# is there so Nginx doesn't hard-error on a missing document root).
op_git_clone_website() {
    local domain="$1" repo_url="$2" branch="${3:-}"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_match "$repo_url" "$RE_GIT_URL" "repo url"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    [[ -e "$dir" ]] && fail "Direktori sudah ada: $dir"

    local branch_args=()
    if [[ -n "$branch" ]]; then
        require_match "$branch" "$RE_GIT_BRANCH" "branch"
        branch_args=(--branch "$branch")
    fi

    if ! git clone --depth 1 "${branch_args[@]}" -- "$repo_url" "$dir" >/tmp/git-clone-out.$$ 2>&1; then
        local out
        out=$(cat "/tmp/git-clone-out.$$" 2>/dev/null)
        rm -f "/tmp/git-clone-out.$$"
        rm -rf -- "$dir"
        fail "Gagal clone repository: ${out}"
    fi
    rm -f "/tmp/git-clone-out.$$"

    mkdir -p "${dir}/public"
    chown -R www-data:www-data "$dir"
    chmod 750 "$dir"
    fm_reapply_terminal_acl "$dir"
    echo "$dir"
}

# Only ever fast-forwards (--ff-only) - never merges/rebases on the
# server unattended. A diverged/conflicting history fails cleanly with a
# clear error instead of silently producing a merge commit or, worse, a
# half-applied working tree nobody asked for.
op_git_pull_website() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    [[ -d "${dir}/.git" ]] || fail "Website ini bukan deployment git (tidak ada .git)"

    if ! git -C "$dir" pull --ff-only >/tmp/git-pull-out.$$ 2>&1; then
        local out
        out=$(cat "/tmp/git-pull-out.$$" 2>/dev/null)
        rm -f "/tmp/git-pull-out.$$"
        fail "Gagal git pull (kemungkinan ada perubahan lokal yang konflik, atau branch sudah divergen): ${out}"
    fi
    rm -f "/tmp/git-pull-out.$$"

    chown -R www-data:www-data "$dir"
    fm_reapply_terminal_acl "$dir"
    echo "OK: pulled $domain"
}

# NUL-terminated key\tvalue records (same reasoning as op_files_list -
# a commit message could legally contain a literal newline).
op_git_status_website() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    if [[ ! -d "${dir}/.git" ]]; then
        printf 'is_git\tno\0'
        return 0
    fi

    local branch commit message date
    branch=$(git -C "$dir" rev-parse --abbrev-ref HEAD 2>/dev/null) || branch="unknown"
    commit=$(git -C "$dir" rev-parse --short HEAD 2>/dev/null) || commit="unknown"
    message=$(git -C "$dir" log -1 --format=%s 2>/dev/null) || message=""
    date=$(git -C "$dir" log -1 --format=%cd --date=relative 2>/dev/null) || date=""

    printf 'is_git\tyes\0branch\t%s\0commit\t%s\0message\t%s\0date\t%s\0' "$branch" "$commit" "$message" "$date"
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

# Terminal di Panel (modules/terminal.sh) grants 'panelterm' a real POSIX
# ACL on WWW_BASE/NODEAPPS_BASE so its sandboxed shell can read/write
# website/app files without being www-data/nodeapps itself. Default ACLs
# are only auto-inherited by the KERNEL for content created DIRECTLY in
# an already-ACL'd directory - several ops below (mktemp+mv, and zip/
# unzip's own internal write-to-temp-then-rename behavior) create the
# real content elsewhere first and move/rename it into place, which does
# NOT trigger that inheritance. Left unfixed, every file touched through
# File Manager silently loses panelterm's grant, and Terminal can `ls`
# but not read/write anything the panel itself created. Re-asserted
# explicitly instead of trusting inheritance. A cheap no-op if Terminal
# was never installed (id panelterm fails) - this file has no other
# dependency on modules/terminal.sh ever having run.
fm_reapply_terminal_acl() {
    local path="$1"
    id panelterm &>/dev/null || return 0
    if [[ -d "$path" ]]; then
        setfacl -R -m "u:panelterm:rwX" -d -m "u:panelterm:rwX" -- "$path" 2>/dev/null || true
    else
        setfacl -m "u:panelterm:rwX" -- "$path" 2>/dev/null || true
    fi
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
        fm_reapply_terminal_acl "$parent_dir"
    fi
    mv "$tmp" "$target"
    chown "$owner" "$target"
    chmod 640 "$target"
    fm_reapply_terminal_acl "$target"
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
    fm_reapply_terminal_acl "$target"
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
    local scope="$1" name="$2" relpath="$3" orphan_confirmed="${4:-}"
    [[ -n "$relpath" ]] || fail "Refusing to delete scope root"
    if fm_is_root_scope "$scope" && [[ "$relpath" != */* ]] && [[ "$orphan_confirmed" != "orphan-confirmed" ]]; then
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
    local scope="$1" name="$2" relpath="$3" newbasename="$4" orphan_confirmed="${5:-}"
    [[ -n "$relpath" ]] || fail "Path sumber wajib diisi"
    fm_require_basename "$newbasename" "nama baru"
    if fm_is_root_scope "$scope" && [[ "$relpath" != */* ]] && [[ "$orphan_confirmed" != "orphan-confirmed" ]]; then
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
    fm_reapply_terminal_acl "$dest"
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
    fm_reapply_terminal_acl "$target_dir"
    echo "OK: extracted zip to ${relpath:-/}"
}

# Extracts a .zip file ALREADY on disk (right-click "Extract" in File
# Manager) into a new sibling folder named after the archive (basename
# minus its extension) - as opposed to op_files_extract_zip above, which
# extracts a freshly-uploaded archive that never touches disk as a .zip
# itself. Same zip-slip guard (extract to a scratch dir, verify every
# entry stays confined, THEN move into place) as op_files_extract_zip.
op_files_extract() {
    local scope="$1" name="$2" relpath="$3"
    require_match "$scope" "$RE_FM_SCOPE" "scope"
    [[ -n "$relpath" ]] || fail "Path file ZIP wajib diisi"
    local target
    target=$(fm_resolve_target "$scope" "$name" "$relpath")
    [[ -f "$target" ]] || fail "File ZIP tidak ditemukan: $relpath"

    local parent base_no_ext dest_dir
    parent=$(dirname "$target")
    base_no_ext=$(basename -- "$target")
    base_no_ext="${base_no_ext%.*}"
    [[ -n "$base_no_ext" ]] || fail "Nama file ZIP tidak valid"
    dest_dir="${parent}/${base_no_ext}"
    [[ -e "$dest_dir" ]] && fail "Sudah ada file/folder bernama '${base_no_ext}' di tujuan - ganti nama salah satunya dulu"

    local tmp_extract
    tmp_extract=$(mktemp -d)
    if ! unzip -q -o "$target" -d "$tmp_extract"; then
        rm -rf "$tmp_extract"
        fail "Gagal mengekstrak ZIP (format tidak valid atau rusak)"
    fi

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

    mv -- "$tmp_extract" "$dest_dir"

    local owner
    owner=$(fm_owner_for_scope "$scope")
    chown -R "$owner" "$dest_dir"
    fm_reapply_terminal_acl "$dest_dir"
    echo "OK: extracted $relpath -> ${base_no_ext}/"
}

# Creates a new .zip from one or more existing files/folders in the same
# directory (multi-select "Compress" in File Manager). Items are bare
# basenames (fm_require_basename rejects '/', so no traversal possible),
# resolved and confinement-checked individually via fm_resolve_target
# before being handed to `zip` - `zip` does not follow symlinks into
# targets outside the selection by default (no -y), storing them as
# symlink entries instead, so it can't be used to smuggle content from
# outside the confined tree into the archive.
op_files_compress() {
    local scope="$1" name="$2" relpath="$3" dest_name="$4"
    shift 4
    require_match "$scope" "$RE_FM_SCOPE" "scope"
    fm_require_basename "$dest_name" "nama ZIP"
    case "$dest_name" in
        *.zip) ;;
        *) dest_name="${dest_name}.zip" ;;
    esac
    [[ $# -ge 1 ]] || fail "Tidak ada item dipilih untuk dikompres"
    [[ $# -le 100 ]] || fail "Maksimal 100 item per kompres"

    local target_dir
    target_dir=$(fm_resolve_target "$scope" "$name" "$relpath")
    [[ -d "$target_dir" ]] || fail "Direktori tidak ditemukan: $relpath"

    local dest_zip="${target_dir}/${dest_name}"
    [[ -e "$dest_zip" ]] && fail "Sudah ada file bernama '${dest_name}' di tujuan - ganti nama dulu"

    local item item_target
    for item in "$@"; do
        fm_require_basename "$item" "item"
        item_target=$(require_path_within "${target_dir}/${item}" "$target_dir")
        [[ -e "$item_target" ]] || fail "Item tidak ditemukan: $item"
    done

    # dest_name is a bare filename (no path prefix, since we `cd` into
    # target_dir first), so a crafted name starting with '-' (e.g. "-T",
    # "-@") would otherwise be parsed by `zip` as an option flag instead
    # of the output filename argument it actually is. Info-ZIP's `--`
    # marker can't be used to guard against that here - it explicitly
    # refuses `--` placed before the archive name ("Invalid command
    # arguments (can't use -- before archive name)"), it's only accepted
    # AFTER the archive name (which is where it's used below, to guard
    # the item list instead). A leading `./` sidesteps the problem the
    # same way `--` would: it can't start with '-' so it's never
    # ambiguous with an option, and resolves to the same file since we're
    # already `cd`'d into target_dir.
    ( cd "$target_dir" && zip -r -q "./${dest_name}" -- "$@" )
    [[ -f "$dest_zip" ]] || fail "Gagal membuat ZIP"

    local owner
    owner=$(fm_owner_for_scope "$scope")
    chown "$owner" "$dest_zip"
    fm_reapply_terminal_acl "$dest_zip"
    echo "OK: compressed $# item(s) -> $dest_name"
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
    local mode="$1" src_scope="$2" src_name="$3" src_relpath="$4" dest_scope="$5" dest_name="$6" dest_relpath="$7" orphan_confirmed="${8:-}"

    require_match "$src_scope" "$RE_FM_SCOPE" "src scope"
    require_match "$dest_scope" "$RE_FM_SCOPE" "dest scope"
    [[ -n "$src_relpath" ]] || fail "Path sumber wajib diisi"
    [[ -n "$dest_relpath" ]] || fail "Path tujuan wajib diisi"

    local src_family dest_family
    src_family=$(fm_scope_family "$src_scope")
    dest_family=$(fm_scope_family "$dest_scope")
    [[ "$src_family" == "$dest_family" ]] || fail "Tidak bisa memindahkan/menyalin antara Website dan Node.js App"

    if fm_is_root_scope "$src_scope" && [[ "$src_relpath" != */* ]] && [[ "$orphan_confirmed" != "orphan-confirmed" ]]; then
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
    fm_reapply_terminal_acl "$dest_target"
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
    local scope="$1" name="$2" relpath="$3" mode="$4" orphan_confirmed="${5:-}"
    require_match "$mode" "$RE_CHMOD_MODE" "mode"
    case "${mode: -1}" in
        2|3|6|7) fail "Mode tidak diizinkan: 'other' (dunia) tidak boleh punya izin tulis" ;;
    esac
    [[ -n "$relpath" ]] || fail "Path wajib diisi"
    if fm_is_root_scope "$scope" && [[ "$relpath" != */* ]] && [[ "$orphan_confirmed" != "orphan-confirmed" ]]; then
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
    fm_reapply_terminal_acl "$dest"
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
RE_CRONID='^(panel-[0-9]+|plugin-[a-z0-9][a-z0-9_-]{0,63})$'

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

# Requests-per-day for a domain's Nginx access log, for the panel's
# Traffic Analysis tab - reads the live log plus whatever Ubuntu's stock
# logrotate for nginx (/etc/logrotate.d/nginx, matches /var/log/nginx/*.log,
# already covers per-site logs since they live in the same directory) has
# rotated so far (.1 uncompressed, .N.gz compressed). Default combined log
# format's date sits inside "[$time_local]" as e.g. "29/Jul/2026:10:15:32
# +0000" - reformatted to YYYY-MM-DD (sortable, unlike the original
# day/Mon/year order) and counted per day. Output: "YYYY-MM-DD<TAB>count",
# chronological.
op_log_traffic_daily() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local base="/var/log/nginx/${domain}-access.log"
    {
        [[ -f "$base" ]] && cat "$base"
        [[ -f "${base}.1" ]] && cat "${base}.1"
        for gz in "${base}".*.gz; do
            [[ -f "$gz" ]] && zcat "$gz"
        done
    } 2>/dev/null | awk '
        {
            split($0, a, "[");
            if (length(a[2]) < 11) next;
            split(a[2], b, ":");
            split(b[1], p, "/");
            m["Jan"]="01"; m["Feb"]="02"; m["Mar"]="03"; m["Apr"]="04";
            m["May"]="05"; m["Jun"]="06"; m["Jul"]="07"; m["Aug"]="08";
            m["Sep"]="09"; m["Oct"]="10"; m["Nov"]="11"; m["Dec"]="12";
            if (!(p[2] in m)) next;
            count[p[3] "-" m[p[2]] "-" p[1]]++;
        }
        END { for (k in count) print k "\t" count[k]; }
    ' | sort
}

# ---------------------------------------------------------------------------
# Plugin system - see PLUGIN_DIR comment near the top of this file for the
# trust model. Every op below re-validates slug/script/path containment
# independently of whatever PHP already checked (PluginService) - same
# double-whitelist discipline as every other privileged operation in this
# script, not weakened just because plugins themselves are trusted.
# ---------------------------------------------------------------------------
# Slug is deliberately NOT a caller-supplied argument here - it comes
# from INSIDE the package's own plugin.json (read via the system PHP CLI
# for real JSON parsing, not a regex guess), which isn't knowable until
# after extraction. Extract to a throwaway temp name first, read the
# slug, THEN move into its final PLUGIN_DIR/<slug> home - the two-step
# avoids ever having to guess a destination before the content that
# determines it has actually been inspected.
plugin_prepare_dir() {
    # This script's `umask 027` would otherwise leave a freshly-created
    # PLUGIN_DIR at 750 (root:root) - the unprivileged 'panel' user
    # (PHP-FPM) couldn't even traverse INTO it to reach an individual
    # plugin's own 755 directory, regardless of that directory's own
    # permissions (every ancestor in the path needs execute/traverse
    # access too, not just the final target).
    mkdir -p "$PLUGIN_DIR"
    chmod 755 "$PLUGIN_DIR"
}

plugin_read_slug() {
    local manifest="$1"
    php -r '
        $d = json_decode(file_get_contents($argv[1]), true);
        echo (is_array($d) && isset($d["slug"]) && is_string($d["slug"])) ? $d["slug"] : "";
    ' "$manifest" 2>/dev/null
}

op_plugin_install_zip() {
    plugin_prepare_dir

    local tmp_zip tmp_extract
    tmp_zip=$(mktemp --suffix=.zip)
    cat > "$tmp_zip"
    if [[ ! -s "$tmp_zip" ]]; then
        rm -f "$tmp_zip"
        fail "File ZIP kosong"
    fi

    tmp_extract=$(mktemp -d)
    if ! unzip -q "$tmp_zip" -d "$tmp_extract"; then
        rm -rf "$tmp_zip" "$tmp_extract"
        fail "Gagal ekstrak ZIP plugin (bukan file ZIP valid?)"
    fi
    rm -f "$tmp_zip"

    # A zip with exactly one top-level folder (GitHub "Download ZIP"
    # style, e.g. "myplugin-main/") uses THAT folder's contents directly,
    # rather than nesting the plugin one level too deep.
    local top_entries top_only content_dir
    top_entries=$(find "$tmp_extract" -mindepth 1 -maxdepth 1 | wc -l)
    top_only=$(find "$tmp_extract" -mindepth 1 -maxdepth 1)
    if [[ "$top_entries" -eq 1 && -d "$top_only" ]]; then
        content_dir="$top_only"
    else
        content_dir="$tmp_extract"
    fi

    if [[ ! -f "${content_dir}/plugin.json" ]]; then
        rm -rf "$tmp_extract"
        fail "plugin.json tidak ditemukan di paket - bukan plugin yang valid"
    fi

    local slug dest
    slug=$(plugin_read_slug "${content_dir}/plugin.json")
    require_match "$slug" "$RE_PLUGIN_SLUG" "plugin slug (dari plugin.json)"
    dest="${PLUGIN_DIR}/${slug}"
    if [[ -e "$dest" ]]; then
        rm -rf "$tmp_extract"
        fail "Plugin '${slug}' sudah ada"
    fi

    mv "$content_dir" "$dest"
    rm -rf "$tmp_extract" 2>/dev/null || true

    plugin_fix_permissions "$dest"
    echo "OK: plugin ${slug} diinstall di ${dest}"
    echo "SLUG:${slug}"
}

op_plugin_install_git() {
    local repo_url="$1" branch="${2:-}"
    require_match "$repo_url" "$RE_GIT_URL" "git url"
    [[ -n "$branch" ]] && require_match "$branch" "$RE_GIT_BRANCH" "branch"
    plugin_prepare_dir

    local tmp_clone
    tmp_clone=$(mktemp -d)
    rmdir "$tmp_clone"
    local clone_args=(--depth 1)
    [[ -n "$branch" ]] && clone_args+=(--branch "$branch")
    if ! GIT_TERMINAL_PROMPT=0 git clone --quiet "${clone_args[@]}" -- "$repo_url" "$tmp_clone"; then
        rm -rf "$tmp_clone"
        fail "Gagal clone repository plugin"
    fi

    if [[ ! -f "${tmp_clone}/plugin.json" ]]; then
        rm -rf "$tmp_clone"
        fail "plugin.json tidak ditemukan di repo - bukan plugin yang valid"
    fi

    local slug dest
    slug=$(plugin_read_slug "${tmp_clone}/plugin.json")
    require_match "$slug" "$RE_PLUGIN_SLUG" "plugin slug (dari plugin.json)"
    dest="${PLUGIN_DIR}/${slug}"
    if [[ -e "$dest" ]]; then
        rm -rf "$tmp_clone"
        fail "Plugin '${slug}' sudah ada"
    fi

    rm -rf "${tmp_clone}/.git"
    mv "$tmp_clone" "$dest"

    plugin_fix_permissions "$dest"
    echo "OK: plugin ${slug} di-clone ke ${dest}"
    echo "SLUG:${slug}"
}

# Manifest (plugin.json) and the plugin's own PHP page files must stay
# readable by the UNPRIVILEGED 'panel' user (PHP-FPM reads them directly
# on every page load - routing through the root-exec bridge for that
# would be needless overhead for content that isn't sensitive). ONLY
# bin/*.sh (the root-exec scripts, see op_plugin_exec below) are locked
# to root-only - that boundary is what actually enforces "root access
# only through the sudo bridge", not a mode a plugin package might ship
# with, so it's reasserted here unconditionally rather than trusted from
# the zip/git source.
plugin_fix_permissions() {
    local dest="$1"
    chown -R root:root "$dest"
    find "$dest" -type d -exec chmod 755 {} \;
    find "$dest" -type f -exec chmod 644 {} \;
    if [[ -d "${dest}/bin" ]]; then
        find "${dest}/bin" -maxdepth 1 -type f -name '*.sh' -exec chmod 700 {} \;
    fi
    if [[ -d "${dest}/cron" ]]; then
        find "${dest}/cron" -maxdepth 1 -type f -exec chmod 700 {} \;
    fi
}

op_plugin_remove() {
    local slug="$1"
    require_match "$slug" "$RE_PLUGIN_SLUG" "plugin slug"
    local dest
    dest=$(require_path_within "${PLUGIN_DIR}/${slug}" "$PLUGIN_DIR")
    [[ -d "$dest" ]] || fail "Plugin tidak ditemukan"
    rm -rf "$dest"
    echo "OK: plugin ${slug} dihapus"
}

# THE trusted root-exec dispatch (chosen explicitly over a sandboxed
# alternative - see migration 2026072902's comment). Whatever script
# exists at PLUGIN_DIR/<slug>/bin/<script>.sh runs with FULL ROOT
# privilege, by design: the whole plugin is trusted at install time, not
# individually whitelisted per-operation the way core panel features are.
# PluginService (PHP) is responsible for only ever calling this for a
# plugin that is actually installed AND enabled, and only for a script
# name present in that plugin's OWN manifest - this layer re-validates
# structurally (path containment, file actually exists) but has no
# database access to re-check "is this plugin enabled" itself.
#
# Runs via `systemd-run --pipe --wait`, NOT as a direct child of this
# process - panel-exec.sh itself is normally invoked as a child of a
# PHP-FPM worker, which (unlike an interactive root shell) runs inside
# ITS OWN systemd sandbox (ProtectSystem=full etc - see
# modules/panel.sh's module_panel_configure_fpm_pool). Confirmed live on
# a real server: ReadWritePaths= punching a hole in that sandbox for
# something as broad as /usr does NOT reliably work even though
# systemd's own docs say it should (reproduced in complete isolation via
# `systemd-run --property=ProtectSystem=full
# --property=ReadWritePaths=/usr` - still read-only). Since plugins are
# explicitly a FULL ROOT trust model (an operator's deliberate choice -
# see wiki/Plugin-Development.md), a plugin script must never end up MORE
# constrained than a plain root shell would be - systemd-run launches it
# as a brand new, independent unit with systemd's normal DEFAULT
# (unsandboxed) properties, escaping the calling PHP-FPM worker's sandbox
# entirely, exactly like installer-self-update already runs update.sh
# for the identical reason. --pipe transparently connects the new unit's
# stdin/stdout/stderr back to this process's own, so Executor::run() on
# the PHP side sees no difference at all.
op_plugin_exec() {
    local slug="$1" script="$2"; shift 2
    require_match "$slug" "$RE_PLUGIN_SLUG" "plugin slug"
    require_match "$script" "$RE_PLUGIN_SCRIPT" "plugin script"
    local script_path
    script_path=$(require_path_within "${PLUGIN_DIR}/${slug}/bin/${script}.sh" "${PLUGIN_DIR}/${slug}")
    [[ -f "$script_path" ]] || fail "Script plugin tidak ditemukan: ${script}"
    systemd-run --pipe --wait --quiet --collect \
        --unit="yuuka-plugin-exec-${slug}-$$" \
        --description="Yuuka plugin exec: ${slug}/${script}" \
        -- bash "$script_path" "$@"
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
    nginx-write-ratelimit-zones) op_nginx_write_ratelimit_zones ;;
    nginx-enable)          op_nginx_enable "$@" ;;
    nginx-disable)         op_nginx_disable "$@" ;;
    nginx-delete)          op_nginx_delete "$@" ;;
    panel-basicauth-set)          op_panel_basicauth_set "$@" ;;
    panel-security-entrance-set)  op_panel_security_entrance_set "$@" ;;
    pm2-deploy)            op_pm2_deploy "$@" ;;
    pm2-start)             op_pm2_start "$@" ;;
    pm2-stop)              op_pm2_stop "$@" ;;
    pm2-restart)           op_pm2_restart "$@" ;;
    pm2-reload)            op_pm2_reload "$@" ;;
    pm2-delete)            op_pm2_delete "$@" ;;
    pm2-jlist)             op_pm2_jlist ;;
    pm2-restart-all)       op_pm2_restart_all ;;
    pm2-describe)          op_pm2_describe "$@" ;;
    pm2-logs)              op_pm2_logs "$@" ;;
    pm2-logs-size)         op_pm2_logs_size "$@" ;;
    pm2-logs-tail)         op_pm2_logs_tail "$@" ;;
    pm2-logs-list)         op_pm2_logs_list "$@" ;;
    pm2-logs-read-archive) op_pm2_logs_read_archive "$@" ;;
    pm2-flush)             op_pm2_flush "$@" ;;
    pm2-reset)             op_pm2_reset "$@" ;;
    pm2-save)              op_pm2_save ;;
    certbot-issue)         op_certbot_issue "$@" ;;
    certbot-remove)        op_certbot_remove "$@" ;;
    panel-ssl-issue)       op_panel_ssl_issue "$@" ;;
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
    git-clone-website)     op_git_clone_website "$@" ;;
    git-pull-website)      op_git_pull_website "$@" ;;
    git-status-website)    op_git_status_website "$@" ;;
    fs-remove-website)     op_fs_remove_website "$@" ;;
    fs-remove-nodeapp)     op_fs_remove_nodeapp "$@" ;;
    port-check)            op_port_check "$@" ;;
    plugin-install-zip)    op_plugin_install_zip "$@" ;;
    plugin-install-git)    op_plugin_install_git "$@" ;;
    plugin-remove)         op_plugin_remove "$@" ;;
    plugin-exec)           op_plugin_exec "$@" ;;
    files-list)            op_files_list "$@" ;;
    files-read)            op_files_read "$@" ;;
    files-write)           op_files_write "$@" ;;
    files-mkdir)           op_files_mkdir "$@" ;;
    files-delete)          op_files_delete "$@" ;;
    files-rename)          op_files_rename "$@" ;;
    files-extract-zip)     op_files_extract_zip "$@" ;;
    files-extract)         op_files_extract "$@" ;;
    files-compress)        op_files_compress "$@" ;;
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
    log-traffic-daily)     op_log_traffic_daily "$@" ;;
    *)
        echo "ERROR: subcommand tidak dikenal: ${SUBCOMMAND}" >&2
        audit "$SUBCOMMAND" "rejected:unknown-subcommand"
        exit 2
        ;;
esac

audit "$SUBCOMMAND" "ok"
