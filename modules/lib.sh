#!/usr/bin/env bash
# ==============================================================================
# lib.sh - Shared helpers for the installer (logging, colors, idempotency)
# Sourced by install.sh and every module. Never executed directly.
# ==============================================================================

# Guard against double-sourcing
if [[ -n "${YUUKA_LIB_LOADED:-}" ]]; then
    return 0 2>/dev/null || exit 0
fi
YUUKA_LIB_LOADED=1

# ---------------------------------------------------------------------------
# Colors
# ---------------------------------------------------------------------------
if [[ -t 1 ]]; then
    C_RESET='\033[0m'
    C_BOLD='\033[1m'
    C_DIM='\033[2m'
    C_RED='\033[0;31m'
    C_GREEN='\033[0;32m'
    C_YELLOW='\033[0;33m'
    C_BLUE='\033[0;34m'
    C_MAGENTA='\033[0;35m'
    C_CYAN='\033[0;36m'
    C_WHITE='\033[0;37m'
else
    C_RESET='' C_BOLD='' C_DIM='' C_RED='' C_GREEN='' C_YELLOW=''
    C_BLUE='' C_MAGENTA='' C_CYAN='' C_WHITE=''
fi

# ---------------------------------------------------------------------------
# Paths / constants
# ---------------------------------------------------------------------------
INSTALL_LOG_DIR="/var/log/yuuka-installer"
INSTALL_LOG_FILE="${INSTALL_LOG_DIR}/install-$(date +%Y%m%d-%H%M%S).log"
DEPLOYMENT_LOG_FILE="${INSTALL_LOG_DIR}/deployment.log"
BACKUP_ROOT="/var/backups/yuuka-installer"
STATE_DIR="/var/lib/yuuka-installer"
STATE_FILE="${STATE_DIR}/install.state"

mkdir -p "$INSTALL_LOG_DIR" "$BACKUP_ROOT" "$STATE_DIR" 2>/dev/null || true

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
log_raw() {
    echo -e "$1" | tee -a "$INSTALL_LOG_FILE" "$DEPLOYMENT_LOG_FILE" >/dev/null
}

log_info() {
    echo -e "${C_CYAN}[INFO]${C_RESET}  $1"
    log_raw "[INFO]  $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_ok() {
    echo -e "${C_GREEN}[ OK ]${C_RESET}  $1"
    log_raw "[ OK ]  $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_warn() {
    echo -e "${C_YELLOW}[WARN]${C_RESET}  $1"
    log_raw "[WARN]  $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_error() {
    echo -e "${C_RED}[FAIL]${C_RESET}  $1" >&2
    log_raw "[FAIL]  $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_step() {
    echo ""
    echo -e "${C_BOLD}${C_BLUE}==>${C_RESET} ${C_BOLD}$1${C_RESET}"
    log_raw "==> $1"
}

die() {
    log_error "$1"
    echo -e "${C_RED}Instalasi dihentikan. Lihat log lengkap di: ${INSTALL_LOG_FILE}${C_RESET}"
    exit 1
}

# ---------------------------------------------------------------------------
# Banner / UI helpers
# ---------------------------------------------------------------------------
print_banner() {
    clear 2>/dev/null || true
    echo -e "${C_BOLD}${C_CYAN}"
    cat <<'EOF'
 __   __              _         ____                              ____                  _
 \ \ / /   _ _   _ _  | | ____ / ___|  ___ _ ____   _____ _ __    |  _ \ __ _ _ __   ___| |
  \ V / | | | | | | | | |/ / _` \___ \ / _ \ '__\ \ / / _ \ '__|   | |_) / _` | '_ \ / _ \ |
   | || |_| | |_| | |_| |   < (_| |___) |  __/ |   \ V /  __/ |      |  __/ (_| | | | |  __/ |
   |_| \__,_|\__,_|\__,_|_|\_\__,_|____/ \___|_|    \_/ \___|_|      |_|   \__,_|_| |_|\___|_|
EOF
    echo -e "${C_RESET}"
    echo -e "${C_DIM}  Automated Web / App Server Installer for Ubuntu Server${C_RESET}"
    echo -e "${C_DIM}  MariaDB - Nginx - Multi-PHP - phpMyAdmin - Node.js/PM2 - Web Panel${C_RESET}"
    echo ""
}

print_section() {
    local title="$1"
    local width=78
    echo ""
    echo -e "${C_BOLD}${C_MAGENTA}$(printf '%.0s─' $(seq 1 $width))${C_RESET}"
    echo -e "${C_BOLD}${C_MAGENTA} $title${C_RESET}"
    echo -e "${C_BOLD}${C_MAGENTA}$(printf '%.0s─' $(seq 1 $width))${C_RESET}"
}

spinner_run() {
    # spinner_run "Deskripsi tugas" -- command args...
    local desc="$1"; shift
    if [[ "$1" == "--" ]]; then shift; fi
    local logfile
    logfile=$(mktemp)
    ( "$@" >"$logfile" 2>&1 )
    local rc=$?
    cat "$logfile" >> "$INSTALL_LOG_FILE"
    if [[ $rc -eq 0 ]]; then
        log_ok "$desc"
    else
        log_error "$desc (exit code $rc)"
        echo -e "${C_DIM}--- Output terakhir ---${C_RESET}"
        tail -n 25 "$logfile"
        echo -e "${C_DIM}-----------------------${C_RESET}"
    fi
    rm -f "$logfile"
    return $rc
}

confirm() {
    # confirm "Pertanyaan?" [default: Y/n]
    local prompt="$1"
    local default="${2:-Y}"
    local yn
    if [[ "$NONINTERACTIVE" == "1" ]]; then
        [[ "$default" =~ ^[Yy]$ ]] && return 0 || return 1
    fi
    if [[ "$default" =~ ^[Yy]$ ]]; then
        read -r -p "$(echo -e "${C_YELLOW}?${C_RESET} ${prompt} [Y/n]: ")" yn
        yn="${yn:-Y}"
    else
        read -r -p "$(echo -e "${C_YELLOW}?${C_RESET} ${prompt} [y/N]: ")" yn
        yn="${yn:-N}"
    fi
    [[ "$yn" =~ ^[Yy]$ ]]
}

ask() {
    # ask VARNAME "Pertanyaan" "default_value"
    local __var="$1" __prompt="$2" __default="${3:-}"
    local __val
    if [[ "$NONINTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' "$__default"
        return 0
    fi
    if [[ -n "$__default" ]]; then
        read -r -p "$(echo -e "${C_YELLOW}?${C_RESET} ${__prompt} [${__default}]: ")" __val
        __val="${__val:-$__default}"
    else
        read -r -p "$(echo -e "${C_YELLOW}?${C_RESET} ${__prompt}: ")" __val
        while [[ -z "$__val" ]]; do
            read -r -p "$(echo -e "${C_RED}  wajib diisi${C_RESET} > ${__prompt}: ")" __val
        done
    fi
    printf -v "$__var" '%s' "$__val"
}

ask_secret() {
    # ask_secret VARNAME "Pertanyaan"
    local __var="$1" __prompt="$2"
    local __val
    if [[ "$NONINTERACTIVE" == "1" ]]; then
        printf -v "$__var" '%s' "$(generate_password)"
        return 0
    fi
    read -r -s -p "$(echo -e "${C_YELLOW}?${C_RESET} ${__prompt}: ")" __val
    echo ""
    while [[ ${#__val} -lt 8 ]]; do
        read -r -s -p "$(echo -e "${C_RED}  minimal 8 karakter${C_RESET} > ${__prompt}: ")" __val
        echo ""
    done
    printf -v "$__var" '%s' "$__val"
}

generate_password() {
    tr -dc 'A-Za-z0-9_@#%' </dev/urandom | head -c 24
}

progress_bar() {
    # progress_bar current total label
    local current=$1 total=$2 label="$3"
    local width=40
    local filled=$(( current * width / total ))
    local empty=$(( width - filled ))
    printf "\r${C_CYAN}["
    printf "%0.s#" $(seq 1 $filled) 2>/dev/null
    printf "%0.s " $(seq 1 $empty) 2>/dev/null
    printf "]${C_RESET} %3d%% %s" $(( current * 100 / total )) "$label"
    if [[ $current -eq $total ]]; then echo ""; fi
}

# ---------------------------------------------------------------------------
# Idempotency helpers
# ---------------------------------------------------------------------------
state_mark() {
    grep -qxF "$1" "$STATE_FILE" 2>/dev/null || echo "$1" >> "$STATE_FILE"
}

state_has() {
    [[ -f "$STATE_FILE" ]] && grep -qxF "$1" "$STATE_FILE" 2>/dev/null
}

pkg_installed() {
    dpkg -s "$1" >/dev/null 2>&1
}

apt_install() {
    # apt_install pkg1 pkg2 ...
    local to_install=()
    local pkg
    for pkg in "$@"; do
        if ! pkg_installed "$pkg"; then
            to_install+=("$pkg")
        fi
    done
    if [[ ${#to_install[@]} -eq 0 ]]; then
        log_ok "Semua package sudah terinstall: $*"
        return 0
    fi
    spinner_run "Install package: ${to_install[*]}" -- apt-get install -y "${to_install[@]}"
}

service_exists() {
    systemctl list-unit-files 2>/dev/null | grep -q "^$1\.service"
}

service_active() {
    systemctl is-active --quiet "$1" 2>/dev/null
}

service_enable_now() {
    local svc="$1"
    # Try enable+start directly instead of gating on service_exists: right
    # after a unit file is written and daemon-reload runs, `systemctl
    # list-unit-files` can still miss it, causing a false "tidak ditemukan"
    # even though the unit is perfectly usable. `systemctl enable` itself
    # is the authoritative check - it fails loudly if the unit is unknown.
    if systemctl enable "$svc" >>"$INSTALL_LOG_FILE" 2>&1; then
        # `start` is a no-op on an already-active unit, so a re-run that
        # rewrote the unit file (new token/config) would otherwise leave
        # the OLD process running under the OLD config forever. `restart`
        # is required to actually pick up the new ExecStart/EnvironmentFile.
        if service_active "$svc"; then
            systemctl restart "$svc" >>"$INSTALL_LOG_FILE" 2>&1 || true
        else
            systemctl start "$svc" >>"$INSTALL_LOG_FILE" 2>&1 || true
        fi
        if service_active "$svc"; then
            log_ok "Service $svc aktif"
        else
            log_warn "Service $svc tidak aktif, cek dengan: systemctl status $svc"
        fi
    else
        log_warn "Service $svc tidak ditemukan atau gagal di-enable, cek dengan: systemctl status $svc"
    fi
}

# ---------------------------------------------------------------------------
# Backup helper: copy file/dir to timestamped backup dir before modification
# ---------------------------------------------------------------------------
backup_path() {
    local target="$1"
    [[ -e "$target" ]] || return 0
    local ts
    ts=$(date +%Y%m%d-%H%M%S)
    local dest="${BACKUP_ROOT}/$(echo "$target" | sed 's#^/##; s#/#_#g')_${ts}"
    mkdir -p "$BACKUP_ROOT"
    cp -a "$target" "$dest" 2>/dev/null && \
        log_info "Backup dibuat: $dest" || \
        log_warn "Gagal backup $target"
}

# ---------------------------------------------------------------------------
# Safe file write: only replaces file if content actually changed
# ---------------------------------------------------------------------------
write_file_if_changed() {
    # write_file_if_changed <path> <content-via-stdin>
    local path="$1"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    if [[ -f "$path" ]] && cmp -s "$tmp" "$path"; then
        rm -f "$tmp"
        return 1
    fi
    backup_path "$path"
    mkdir -p "$(dirname "$path")"
    mv "$tmp" "$path"
    return 0
}

require_root() {
    if [[ "$EUID" -ne 0 ]]; then
        die "Script ini harus dijalankan sebagai root. Gunakan: sudo bash install.sh"
    fi
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}
