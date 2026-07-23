#!/usr/bin/env bash
# ==============================================================================
# php.sh - Install multiple parallel PHP-FPM versions with common extensions
# ==============================================================================

PHP_VERSIONS=("7.4" "8.0" "8.1" "8.2" "8.3" "8.4")
PHP_EXTENSIONS=(bcmath curl fpm gd intl mbstring mysql opcache readline soap sqlite3 xml zip)
PHP_OPTIONAL_EXTENSIONS=(redis imagick)
PHP_INSTALLED_VERSIONS=()

module_php_add_repo() {
    log_step "Menambahkan repository PHP (ondrej/php PPA)"

    if grep -Rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
        log_ok "Repository ondrej/php sudah terdaftar"
    else
        spinner_run "add-apt-repository ppa:ondrej/php" -- add-apt-repository -y ppa:ondrej/php
        spinner_run "apt-get update setelah menambah PPA" -- apt-get update -y
    fi
    state_mark "php:repo_added"
}

module_php_install_version() {
    local version="$1"
    log_info "Memproses PHP ${version} ..."

    local pkg_list=("php${version}")
    local ext
    for ext in "${PHP_EXTENSIONS[@]}"; do
        pkg_list+=("php${version}-${ext}")
    done

    # Probe availability first so one missing package doesn't abort the whole run
    local available_pkgs=()
    for pkg in "${pkg_list[@]}"; do
        if apt-cache show "$pkg" >/dev/null 2>&1; then
            available_pkgs+=("$pkg")
        else
            log_warn "Package tidak tersedia untuk PHP ${version}: ${pkg} (dilewati)"
        fi
    done

    if [[ ${#available_pkgs[@]} -eq 0 ]]; then
        log_warn "PHP ${version} tidak tersedia di repository untuk distro ini, dilewati"
        return 1
    fi

    if ! apt_install "${available_pkgs[@]}"; then
        log_warn "Sebagian package PHP ${version} gagal terinstall"
        return 1
    fi

    # Optional extensions - best effort, never fatal
    for ext in "${PHP_OPTIONAL_EXTENSIONS[@]}"; do
        local pkg="php${version}-${ext}"
        if apt-cache show "$pkg" >/dev/null 2>&1; then
            apt_install "$pkg" || log_warn "Gagal install extension opsional ${pkg}"
        fi
    done

    if ! pkg_installed "php${version}-fpm"; then
        log_warn "php${version}-fpm tidak berhasil terinstall, versi ini tidak akan diaktifkan"
        return 1
    fi

    module_php_configure_pool "$version"
    service_enable_now "php${version}-fpm"

    PHP_INSTALLED_VERSIONS+=("$version")
    log_ok "PHP ${version} siap (php${version}-fpm)"
    state_mark "php:installed:${version}"
    return 0
}

module_php_configure_pool() {
    local version="$1"
    local pool_conf="/etc/php/${version}/fpm/pool.d/www.conf"
    [[ -f "$pool_conf" ]] || return 0

    backup_path "$pool_conf"

    # Sensible production-ready defaults, applied idempotently
    sed -i \
        -e "s/^;\?pm\.max_children.*/pm.max_children = 20/" \
        -e "s/^;\?pm\.start_servers.*/pm.start_servers = 4/" \
        -e "s/^;\?pm\.min_spare_servers.*/pm.min_spare_servers = 2/" \
        -e "s/^;\?pm\.max_spare_servers.*/pm.max_spare_servers = 8/" \
        -e "s/^;\?pm\.max_requests.*/pm.max_requests = 500/" \
        "$pool_conf"

    local ini_file="/etc/php/${version}/fpm/php.ini"
    if [[ -f "$ini_file" ]]; then
        backup_path "$ini_file"
        sed -i \
            -e "s/^expose_php.*/expose_php = Off/" \
            -e "s/^upload_max_filesize.*/upload_max_filesize = 64M/" \
            -e "s/^post_max_size.*/post_max_size = 64M/" \
            -e "s/^;\?cgi\.fix_pathinfo.*/cgi.fix_pathinfo = 0/" \
            "$ini_file"
    fi
}

module_php_select_default() {
    log_step "Memilih versi PHP default"

    if [[ ${#PHP_INSTALLED_VERSIONS[@]} -eq 0 ]]; then
        log_warn "Tidak ada versi PHP yang berhasil diinstall, lewati pemilihan default"
        return 0
    fi

    echo -e "${C_DIM}Versi PHP yang terinstall: ${PHP_INSTALLED_VERSIONS[*]}${C_RESET}"
    local recommended="${PHP_INSTALLED_VERSIONS[-1]}"
    ask PHP_DEFAULT_VERSION "Pilih versi PHP default untuk CLI & phpMyAdmin" "$recommended"

    if [[ ! " ${PHP_INSTALLED_VERSIONS[*]} " =~ " ${PHP_DEFAULT_VERSION} " ]]; then
        log_warn "Versi ${PHP_DEFAULT_VERSION} tidak valid, menggunakan ${recommended}"
        PHP_DEFAULT_VERSION="$recommended"
    fi

    update-alternatives --set php "/usr/bin/php${PHP_DEFAULT_VERSION}" >>"$INSTALL_LOG_FILE" 2>&1 || true
    export PHP_DEFAULT_VERSION
    log_ok "PHP CLI default: ${PHP_DEFAULT_VERSION}"
    state_mark "php:default_selected"
}

module_php_run_all() {
    log_step "Install PHP-FPM (multi-versi paralel)"
    module_php_add_repo

    local v
    for v in "${PHP_VERSIONS[@]}"; do
        if state_has "php:installed:${v}" && pkg_installed "php${v}-fpm"; then
            log_ok "PHP ${v} sudah terinstall sebelumnya, lewati"
            PHP_INSTALLED_VERSIONS+=("$v")
            continue
        fi
        module_php_install_version "$v" || true
    done

    if [[ ${#PHP_INSTALLED_VERSIONS[@]} -eq 0 ]]; then
        die "Tidak ada satupun versi PHP yang berhasil diinstall. Periksa koneksi internet / repository."
    fi

    module_php_select_default
    export PHP_INSTALLED_VERSIONS
}
