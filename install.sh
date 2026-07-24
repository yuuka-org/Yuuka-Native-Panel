#!/usr/bin/env bash
# ==============================================================================
# install.sh - Master installer: Ubuntu Web/App Server + Management Panel
#
# Usage:
#   sudo bash install.sh
#
# Safe to re-run: every module checks existing state before acting and never
# destroys user configuration or data that already exists.
# ==============================================================================
set -uo pipefail
trap 'echo -e "\n\033[0;31m[FATAL]\033[0m Instalasi terhenti tidak terduga pada baris $LINENO. Lihat log: ${INSTALL_LOG_FILE:-/var/log/yuuka-installer/}\n"; exit 1' ERR

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export SCRIPT_DIR
NONINTERACTIVE="${NONINTERACTIVE:-0}"
export NONINTERACTIVE

# ---------------------------------------------------------------------------
# Load shared library + modules
# ---------------------------------------------------------------------------
# shellcheck source=modules/lib.sh
source "${SCRIPT_DIR}/modules/lib.sh"
# shellcheck source=modules/system.sh
source "${SCRIPT_DIR}/modules/system.sh"
# shellcheck source=modules/mariadb.sh
source "${SCRIPT_DIR}/modules/mariadb.sh"
# shellcheck source=modules/nginx.sh
source "${SCRIPT_DIR}/modules/nginx.sh"
# shellcheck source=modules/php.sh
source "${SCRIPT_DIR}/modules/php.sh"
# shellcheck source=modules/nodejs.sh
source "${SCRIPT_DIR}/modules/nodejs.sh"
# shellcheck source=modules/phpmyadmin.sh
source "${SCRIPT_DIR}/modules/phpmyadmin.sh"
# shellcheck source=modules/ssl.sh
source "${SCRIPT_DIR}/modules/ssl.sh"
# shellcheck source=modules/cloudflare.sh
source "${SCRIPT_DIR}/modules/cloudflare.sh"
# shellcheck source=modules/panel.sh
source "${SCRIPT_DIR}/modules/panel.sh"
# shellcheck source=modules/terminal.sh
source "${SCRIPT_DIR}/modules/terminal.sh"

TOTAL_STEPS=14
CURRENT_STEP=0

step_progress() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    echo ""
    echo -e "${C_DIM}[Tahap ${CURRENT_STEP}/${TOTAL_STEPS}]${C_RESET}"
}

main() {
    print_banner
    log_info "Log instalasi lengkap: ${INSTALL_LOG_FILE}"

    print_section "1. Pemeriksaan Sistem"
    step_progress
    module_system_check_root
    module_system_check_ubuntu

    print_section "2. Konfigurasi Awal"
    echo "Masukkan informasi dasar untuk panel manajemen server."
    echo ""
    ask PANEL_DOMAIN "Domain untuk panel (contoh: panel.domainanda.com)" ""
    ask PANEL_ADMIN_EMAIL "Email administrator (untuk SSL & notifikasi)" ""
    export PANEL_DOMAIN PANEL_ADMIN_EMAIL

    echo ""
    echo "Pilih mode deployment panel:"
    echo "  [1] Direct   - Nginx + IP publik + Let's Encrypt"
    echo "  [2] Tunnel   - Cloudflare Tunnel (tanpa expose port publik)"
    echo "  [3] Hybrid   - Nginx + Cloudflare Tunnel sekaligus"
    ask DEPLOY_MODE_CHOICE "Pilihan (1/2/3)" "1"
    case "$DEPLOY_MODE_CHOICE" in
        2) PANEL_DEPLOYMENT_MODE="tunnel" ;;
        3) PANEL_DEPLOYMENT_MODE="hybrid" ;;
        *) PANEL_DEPLOYMENT_MODE="direct" ;;
    esac
    export PANEL_DEPLOYMENT_MODE
    log_ok "Mode deployment: ${PANEL_DEPLOYMENT_MODE}"

    print_section "3. Update & Dependency Sistem"
    step_progress
    module_system_update
    module_system_dependencies
    module_system_timezone
    module_system_firewall
    module_system_create_users

    print_section "4. Database - MariaDB"
    step_progress
    module_mariadb_run_all

    print_section "5. Web Server - Nginx"
    step_progress
    module_nginx_run_all

    print_section "6. PHP Multi-Version (PHP-FPM)"
    step_progress
    module_php_run_all

    print_section "7. Node.js + NVM + PM2"
    step_progress
    module_nodejs_run_all

    print_section "8. phpMyAdmin"
    step_progress
    module_phpmyadmin_run_all
    if [[ ${#PHP_INSTALLED_VERSIONS[@]} -gt 0 ]]; then
        echo ""
        echo "Bagaimana phpMyAdmin ingin diakses?"
        echo "  [1] Subdomain terpisah (pma.domainanda.com)"
        echo "  [2] Path di bawah domain panel (https://${PANEL_DOMAIN}/phpmyadmin)"
        ask PMA_MODE_CHOICE "Pilihan (1/2)" "1"
        if [[ "$PMA_MODE_CHOICE" == "1" ]]; then
            ask PMA_DOMAIN "Subdomain phpMyAdmin" "pma.${PANEL_DOMAIN#panel.}"
            module_phpmyadmin_generate_nginx "$PHP_DEFAULT_VERSION" "subdomain" "$PMA_DOMAIN" || true
        else
            module_phpmyadmin_generate_nginx "$PHP_DEFAULT_VERSION" "path" "$PANEL_DOMAIN" || true
        fi
    fi

    print_section "9. Terminal di Panel"
    step_progress
    # Generates its own Nginx snippet only (module_terminal_generate_nginx) -
    # must run BEFORE module_panel_run_all below regenerates the panel
    # vhost, exactly the same ordering constraint phpMyAdmin's path-mode
    # snippet already has (see modules/panel.sh's $terminal_include).
    module_terminal_run_all || log_warn "Setup Terminal tidak lengkap - menu Terminal di panel mungkin tidak berfungsi, cek log di atas. Bisa dicoba lagi nanti lewat 'yp custom-build terminal'."

    print_section "10. Panel Manajemen Web"
    step_progress
    module_panel_run_all

    print_section "11. SSL / Let's Encrypt"
    step_progress
    if [[ "$PANEL_DEPLOYMENT_MODE" != "tunnel" ]]; then
        module_ssl_prompt_for_panel "$PANEL_DOMAIN" "$PANEL_ADMIN_EMAIL"
    else
        log_info "Mode tunnel dipilih, SSL publik dilewati (Cloudflare menangani TLS di edge)."
        PANEL_SSL_ENABLED="0"
    fi

    print_section "12. Cloudflare Tunnel"
    step_progress
    if [[ "$PANEL_DEPLOYMENT_MODE" == "tunnel" || "$PANEL_DEPLOYMENT_MODE" == "hybrid" ]]; then
        module_cloudflare_prompt_setup || log_warn "Cloudflare Tunnel tidak dikonfigurasi"
    else
        log_info "Mode direct dipilih, Cloudflare Tunnel dilewati (bisa diaktifkan nanti dari panel)."
    fi

    print_section "13. Finalisasi Service"
    step_progress
    systemctl restart nginx mariadb "php${PHP_DEFAULT_VERSION}-fpm" >>"$INSTALL_LOG_FILE" 2>&1 || true
    log_ok "Service inti direstart"

    print_section "14. Selesai"
    step_progress
    print_summary
}

print_summary() {
    local proto="http"
    [[ "${PANEL_SSL_ENABLED:-0}" == "1" ]] && proto="https"

    echo ""
    echo -e "${C_BOLD}${C_GREEN}$(printf '%.0s═' $(seq 1 78))${C_RESET}"
    echo -e "${C_BOLD}${C_GREEN}  INSTALASI SELESAI${C_RESET}"
    echo -e "${C_BOLD}${C_GREEN}$(printf '%.0s═' $(seq 1 78))${C_RESET}"
    echo ""
    echo -e "  ${C_BOLD}Panel URL${C_RESET}       : ${C_CYAN}${proto}://${PANEL_DOMAIN}${C_RESET}"
    echo -e "  ${C_BOLD}Username${C_RESET}        : ${C_CYAN}${PANEL_ADMIN_USERNAME:-admin}${C_RESET}"
    echo -e "  ${C_BOLD}Password${C_RESET}        : ${C_CYAN}${PANEL_ADMIN_PASSWORD:-<sudah diset sebelumnya>}${C_RESET}"
    echo ""
    echo -e "  ${C_BOLD}PHP versions${C_RESET}    : ${PHP_INSTALLED_VERSIONS[*]:-none} (default: ${PHP_DEFAULT_VERSION:-N/A})"
    echo -e "  ${C_BOLD}Node.js${C_RESET}         : 18, 20, 22 via NVM (default: ${NODE_DEFAULT_VERSION:-N/A}), dikelola PM2 (user: nodeapps)"
    echo -e "  ${C_BOLD}Deployment mode${C_RESET} : ${PANEL_DEPLOYMENT_MODE}"
    echo ""
    echo -e "  ${C_BOLD}Direktori panel${C_RESET} : ${PANEL_ROOT}"
    echo -e "  ${C_BOLD}Log instalasi${C_RESET}   : ${INSTALL_LOG_FILE}"
    echo ""
    echo -e "${C_YELLOW}  PENTING: catat password di atas sekarang, tidak akan ditampilkan lagi.${C_RESET}"
    echo -e "${C_YELLOW}  Jika DNS domain belum diarahkan ke server ini, arahkan A record lalu jalankan${C_RESET}"
    echo -e "${C_YELLOW}  ulang penerbitan SSL dari menu Domain Management di panel.${C_RESET}"
    echo ""
    echo -e "${C_BOLD}${C_GREEN}$(printf '%.0s═' $(seq 1 78))${C_RESET}"

    {
        echo "=== RINGKASAN INSTALASI $(date) ==="
        echo "Panel URL: ${proto}://${PANEL_DOMAIN}"
        echo "Username : ${PANEL_ADMIN_USERNAME:-admin}"
        echo "PHP versions: ${PHP_INSTALLED_VERSIONS[*]:-none} (default ${PHP_DEFAULT_VERSION:-N/A})"
        echo "Deployment mode: ${PANEL_DEPLOYMENT_MODE}"
    } >> "$INSTALL_LOG_FILE"
}

main "$@"
