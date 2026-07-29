#!/usr/bin/env bash
# ==============================================================================
# update.sh - One-shot updater for an already-installed panel. Run from your
# existing repo clone:
#   cd ~/Yuuka-Native-Panel && sudo bash update.sh
#
# Also what runs in the background when an operator clicks "Update" in the
# panel itself (Settings > System, via panel-exec.sh's installer-self-update
# -> systemd-run -> this script) - there is no terminal/SSH session attached
# in that case, so EVERY config-generating module below must be fully
# non-interactive and safe to blindly re-run on an already-configured server.
#
# Pulls the latest code (if this clone tracks a git remote), redeploys
# panel-src/, then REBUILDS every system-level module (Nginx base config,
# PHP-FPM pools, phpMyAdmin, Terminal, SSL/certbot) using the same functions
# install.sh itself uses - so a fix shipped in modules/*.sh (e.g. an Nginx
# vhost rule, an FPM pool setting, a systemd unit) actually takes effect
# right after updating, without an operator ever needing to open a terminal
# and run 'yp custom-build <module>' by hand. Every module function called
# here is idempotent (state-gated installs, write_file_if_changed for
# generated configs) - re-running them on a server that's already fully set
# up changes nothing except configuration DRIFT back in line with the
# current code. Safe to re-run any time.
# ==============================================================================
set -uo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Jalankan sebagai root: sudo bash update.sh" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export SCRIPT_DIR
NONINTERACTIVE="${NONINTERACTIVE:-0}"
export NONINTERACTIVE

if git -C "$SCRIPT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "==> git pull di ${SCRIPT_DIR}"
    git -C "$SCRIPT_DIR" pull || echo "  (git pull gagal/dilewati - lanjut dengan kode yang sudah ada di sini)"
    # Always print the commit actually in play here, regardless of whether
    # this pull itself found anything new - when update.sh runs via the
    # panel's own "Update" button (panel-exec.sh's op_installer_self_update),
    # THAT command already did its own git fetch + merge --ff-only on this
    # exact directory moments before scheduling this script, so this pull
    # is routinely a correct-but-confusing no-op ("Already up to date.")
    # that reads like nothing happened even though the newest code is
    # already checked out. Showing the commit hash/date removes the
    # ambiguity either way - a manual `sudo bash update.sh` run (no prior
    # fetch) benefits from this exact same line too.
    echo "  commit aktif: $(git -C "$SCRIPT_DIR" log -1 --format='%h (%cd)' --date=short 2>/dev/null || echo 'tidak diketahui')"
fi

# shellcheck source=modules/lib.sh
source "${SCRIPT_DIR}/modules/lib.sh"
# shellcheck source=modules/nginx.sh
source "${SCRIPT_DIR}/modules/nginx.sh"
# shellcheck source=modules/php.sh
source "${SCRIPT_DIR}/modules/php.sh"
# shellcheck source=modules/phpmyadmin.sh
source "${SCRIPT_DIR}/modules/phpmyadmin.sh"
# shellcheck source=modules/ssl.sh
source "${SCRIPT_DIR}/modules/ssl.sh"
# shellcheck source=modules/terminal.sh
source "${SCRIPT_DIR}/modules/terminal.sh"
# shellcheck source=modules/panel.sh
source "${SCRIPT_DIR}/modules/panel.sh"

if [[ ! -d "$PANEL_ROOT" ]]; then
    die "Panel belum pernah di-deploy (${PANEL_ROOT} tidak ada). Jalankan 'sudo bash install.sh' dulu untuk instalasi pertama."
fi

# Reuses the exact functions install.sh itself calls in module_panel_run_all -
# keeps /opt/yuuka-installer (and the `yp` binary) in sync, then redeploys
# panel-src/. See modules/panel.sh for what each of these actually does.
module_panel_setup_installer_copy
module_panel_deploy_files

# ---------------------------------------------------------------------------
# Detect this install's own domain/PHP version/phpMyAdmin access mode from
# what's already on disk (never re-ask - there may be no terminal attached
# to answer). Mirrors the detection 'yp repair panel'/'yp custom-build'
# already do standalone; needed here too since php/phpMyAdmin/terminal are
# regenerated as part of update, not just the panel vhost.
# ---------------------------------------------------------------------------
PANEL_VHOST_FILE=$(find "$NGINX_SITES_AVAILABLE" -maxdepth 1 -name 'panel-*.conf' 2>/dev/null | head -1)
PANEL_DOMAIN=$(grep -h 'server_name' "${PANEL_VHOST_FILE:-/dev/null}" 2>/dev/null | head -1 | awk '{print $2}' | tr -d ';')
export PANEL_DOMAIN

log_step "Rebuild konfigurasi sistem (Nginx, PHP-FPM, phpMyAdmin, Terminal, SSL)"

module_nginx_run_all
module_php_run_all

# phpMyAdmin: only rebuild if it was actually set up before, and only in
# whichever access mode is already live - detected straight from the
# generated Nginx files themselves (not the free-text "URL phpMyAdmin"
# field in Settings, which an operator could have overwritten by hand and
# which may not even point at this server).
PMA_PATH_SNIPPET="${NGINX_SNIPPETS}/includes/phpmyadmin.conf"
PMA_SUBDOMAIN_CONF=$(find "$NGINX_SITES_AVAILABLE" -maxdepth 1 -name 'pma-*.conf' 2>/dev/null | head -1)

if [[ -f "$PMA_PATH_SNIPPET" && -n "$PANEL_DOMAIN" ]]; then
    module_phpmyadmin_run_all && module_phpmyadmin_generate_nginx "${PHP_DEFAULT_VERSION:-8.3}" "path" "$PANEL_DOMAIN" \
        || log_warn "Rebuild phpMyAdmin (mode path) gagal, cek log di atas. Bisa dicoba lagi: sudo yp custom-build phpmyadmin"
elif [[ -n "$PMA_SUBDOMAIN_CONF" ]]; then
    PMA_DOMAIN="$(basename "$PMA_SUBDOMAIN_CONF" .conf)"
    PMA_DOMAIN="${PMA_DOMAIN#pma-}"
    module_phpmyadmin_run_all && module_phpmyadmin_generate_nginx "${PHP_DEFAULT_VERSION:-8.3}" "subdomain" "$PMA_DOMAIN" \
        || log_warn "Rebuild phpMyAdmin (mode subdomain) gagal, cek log di atas. Bisa dicoba lagi: sudo yp custom-build phpmyadmin"
else
    log_info "phpMyAdmin belum pernah dikonfigurasi (atau domain panel tidak terdeteksi) - lewati rebuild-nya"
fi

# Generates its own Nginx snippet only - must run BEFORE 'yp repair panel'
# below regenerates the panel vhost, same ordering constraint install.sh
# itself has (see install.sh's section 9 comment).
module_terminal_run_all || log_warn "Rebuild Terminal tidak lengkap - menu Terminal di panel mungkin tidak berfungsi, cek log di atas. Bisa dicoba lagi: sudo yp custom-build terminal"

module_ssl_run_all

if command_exists yp; then
    log_step "Menerapkan perbaikan config (yp repair panel)"
    yp repair panel
else
    log_warn "Perintah 'yp' belum tersedia di PATH - lewati repair otomatis. Jalankan 'sudo bash update.sh' sekali lagi, atau 'sudo yp repair panel' manual."
fi

log_ok "Update selesai - Nginx, PHP-FPM, phpMyAdmin, Terminal, SSL, dan Panel sudah direbuild otomatis."
command_exists yp && yp version
