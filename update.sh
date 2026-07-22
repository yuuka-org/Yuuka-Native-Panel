#!/usr/bin/env bash
# ==============================================================================
# update.sh - One-shot updater for an already-installed panel. Run from your
# existing repo clone:
#   cd ~/Yuuka-Native-Panel && sudo bash update.sh
#
# Pulls the latest code (if this clone tracks a git remote), then reuses the
# exact same panel-deploy + installer-sync logic install.sh itself uses -
# no interactive wizard, no re-asking domain/email/SSL/Cloudflare questions.
# Safe to re-run any time.
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
fi

# shellcheck source=modules/lib.sh
source "${SCRIPT_DIR}/modules/lib.sh"
# shellcheck source=modules/nginx.sh
source "${SCRIPT_DIR}/modules/nginx.sh"
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

if command_exists yp; then
    log_step "Menerapkan perbaikan config (yp repair panel)"
    yp repair panel
else
    log_warn "Perintah 'yp' belum tersedia di PATH - lewati repair otomatis. Jalankan 'sudo bash update.sh' sekali lagi, atau 'sudo yp repair panel' manual."
fi

log_ok "Update selesai."
command_exists yp && yp version
