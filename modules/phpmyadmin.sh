#!/usr/bin/env bash
# ==============================================================================
# phpmyadmin.sh - Install phpMyAdmin manually (no Apache dependency) served by
#                  Nginx + PHP-FPM, accessible via /phpmyadmin or a subdomain.
# ==============================================================================

PHPMYADMIN_VERSION="${PHPMYADMIN_VERSION:-5.2.1}"
PHPMYADMIN_ROOT="/usr/share/phpmyadmin"
PHPMYADMIN_TMP_DIR="/var/lib/phpmyadmin/tmp"
PHPMYADMIN_POOL_SOCK="/run/php/phpmyadmin.sock"
# Shared with the panel's own PHP-FPM pool for signon SSO (see
# pma_redirect.php) - a dedicated pool is required because the default
# 'www' pool phpMyAdmin would otherwise use has no relationship to the
# panel's sandboxed session storage (panel's open_basedir cannot reach
# the distro-default session.save_path a generic pool would use, and vice
# versa a generic pool cannot reach the panel's storage/ directory).
PHPMYADMIN_SIGNON_SESSION_DIR="/opt/server-panel/storage/pma-signon"

module_phpmyadmin_download() {
    log_step "Download & install phpMyAdmin ${PHPMYADMIN_VERSION}"

    if [[ -f "${PHPMYADMIN_ROOT}/index.php" ]]; then
        log_ok "phpMyAdmin sudah terinstall di ${PHPMYADMIN_ROOT}"
        state_mark "phpmyadmin:downloaded"
        return 0
    fi

    local tmp_archive
    tmp_archive=$(mktemp --suffix=.tar.gz)
    local url="https://files.phpmyadmin.net/phpMyAdmin/${PHPMYADMIN_VERSION}/phpMyAdmin-${PHPMYADMIN_VERSION}-all-languages.tar.gz"

    if ! spinner_run "Download phpMyAdmin ${PHPMYADMIN_VERSION}" -- curl -fsSL -o "$tmp_archive" "$url"; then
        rm -f "$tmp_archive"
        log_warn "Gagal download phpMyAdmin ${PHPMYADMIN_VERSION}. Lewati instalasi phpMyAdmin."
        return 1
    fi

    mkdir -p "$PHPMYADMIN_ROOT"
    spinner_run "Ekstrak phpMyAdmin" -- tar -xzf "$tmp_archive" -C "$PHPMYADMIN_ROOT" --strip-components=1
    rm -f "$tmp_archive"

    mkdir -p "$PHPMYADMIN_TMP_DIR"
    chown -R www-data:www-data "$PHPMYADMIN_ROOT" "$PHPMYADMIN_TMP_DIR"
    chmod 750 "$PHPMYADMIN_TMP_DIR"

    state_mark "phpmyadmin:downloaded"
}

module_phpmyadmin_configure() {
    log_step "Konfigurasi phpMyAdmin"

    local blowfish_secret
    blowfish_secret=$(openssl rand -base64 32 | tr -d '\n')

    write_file_if_changed "${PHPMYADMIN_ROOT}/config.inc.php" <<EOF
<?php
declare(strict_types=1);

\$cfg['blowfish_secret'] = '${blowfish_secret}';

\$i = 0;
\$i++;
\$cfg['Servers'][\$i]['auth_type']     = 'signon';
\$cfg['Servers'][\$i]['SignonSession'] = 'PMASignon';
\$cfg['Servers'][\$i]['SignonURL']     = 'https://${PANEL_DOMAIN}/login.php';
\$cfg['Servers'][\$i]['host']          = '127.0.0.1';
\$cfg['Servers'][\$i]['port']          = '3306';
\$cfg['Servers'][\$i]['compress']      = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['Servers'][\$i]['ssl']           = false;

\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';
\$cfg['TempDir'] = '${PHPMYADMIN_TMP_DIR}';
\$cfg['LoginCookieValidity'] = 1800;
\$cfg['ForceSSL'] = false;
\$cfg['CheckConfigurationPermissions'] = true;
EOF

    chown www-data:www-data "${PHPMYADMIN_ROOT}/config.inc.php"
    chmod 640 "${PHPMYADMIN_ROOT}/config.inc.php"

    log_ok "config.inc.php dibuat dengan blowfish_secret unik"
    state_mark "phpmyadmin:configured"
}

# Dedicated PHP-FPM pool for phpMyAdmin (instead of the default 'www'
# pool) so it can share a session directory with the panel's own pool for
# signon SSO (see pma_redirect.php). Runs as www-data (matching
# phpMyAdmin's existing file ownership) - www-data is already a member of
# the 'panel' group (see module_panel_deploy_files in modules/panel.sh),
# so it can read/write PHPMYADMIN_SIGNON_SESSION_DIR when that directory
# is owned panel:panel with group-write permission.
module_phpmyadmin_configure_fpm_pool() {
    local php_version="$1"
    log_step "Konfigurasi PHP-FPM pool khusus phpMyAdmin (PHP ${php_version})"

    mkdir -p "$PHPMYADMIN_SIGNON_SESSION_DIR"
    chown panel:panel "$PHPMYADMIN_SIGNON_SESSION_DIR"
    chmod 770 "$PHPMYADMIN_SIGNON_SESSION_DIR"
    if id www-data &>/dev/null; then
        usermod -a -G panel www-data
    fi

    local pool_file="/etc/php/${php_version}/fpm/pool.d/phpmyadmin.conf"
    write_file_if_changed "$pool_file" <<EOF
[phpmyadmin]
user = www-data
group = www-data
listen = ${PHPMYADMIN_POOL_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 6
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 500

; Session save_path is the directory shared with the panel's PHP-FPM
; pool for signon SSO - see pma_redirect.php and
; PHPMYADMIN_SIGNON_SESSION_DIR above.
php_admin_value[session.save_path] = ${PHPMYADMIN_SIGNON_SESSION_DIR}
php_admin_value[open_basedir] = ${PHPMYADMIN_ROOT}:${PHPMYADMIN_SIGNON_SESSION_DIR}:${PHPMYADMIN_TMP_DIR}:/tmp
php_admin_value[error_log] = /var/log/php-phpmyadmin-fpm.log
php_admin_flag[log_errors] = on
EOF

    systemctl restart "php${php_version}-fpm"
    log_ok "Pool 'phpmyadmin' aktif pada php${php_version}-fpm (${PHPMYADMIN_POOL_SOCK})"
    state_mark "phpmyadmin:fpm_pool"
}

# Registers phpMyAdmin under a chosen PHP-FPM version + access mode.
# This is invoked interactively from install.sh after PHP & panel domain are known.
module_phpmyadmin_generate_nginx() {
    local php_version="$1"      # e.g. 8.3
    local access_mode="$2"      # "path" or "subdomain"
    local domain="$3"           # panel domain (path mode) or full pma subdomain (subdomain mode)

    log_step "Generate konfigurasi Nginx untuk phpMyAdmin (${access_mode})"

    module_phpmyadmin_configure_fpm_pool "$php_version"

    local sock="$PHPMYADMIN_POOL_SOCK"
    local conf_name
    local conf_content

    if [[ "$access_mode" == "subdomain" ]]; then
        conf_name="pma-${domain}"
        conf_content="server {
    listen 80;
    listen [::]:80;
    server_name ${domain};

    include ${NGINX_SNIPPETS}/acme-challenge.conf;

    root ${PHPMYADMIN_ROOT};
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ ^/(.+\\.php)\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${sock};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\\. { deny all; }
    include ${NGINX_SNIPPETS}/security-headers.conf;
}"
    else
        conf_name="phpmyadmin-path-${domain}"
        conf_content="# Included from the site's server block via 'include' if desired.
# Bare '/phpmyadmin' (no trailing slash) does NOT match the ^~ /phpmyadmin/
# prefix location below - without this exact-match redirect it silently
# falls through to the site's own catch-all location (e.g. the panel's
# own index.php), which looks like phpMyAdmin 'redirects to the dashboard'.
location = /phpmyadmin {
    return 301 /phpmyadmin/;
}

location ^~ /phpmyadmin/ {
    alias ${PHPMYADMIN_ROOT}/;
    index index.php;

    location ~ ^/phpmyadmin/(.+\\.php)\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${sock};
        # \$document_root does NOT reliably resolve to the parent
        # location's 'alias' target inside a nested regex location block -
        # this is a well-known nginx gotcha (alias + nested PHP location)
        # that leaves PHP-FPM with an empty/wrong path, hence
        # 'No input file specified.'. PHPMYADMIN_ROOT is known at config
        # generation time, so it is hardcoded here instead of relying on
        # nginx to resolve it at request time.
        fastcgi_param SCRIPT_FILENAME ${PHPMYADMIN_ROOT}/\$1;
        fastcgi_param SCRIPT_NAME /phpmyadmin/\$1;
    }

    location ~ /\\. { deny all; }
}"
    fi

    if [[ "$access_mode" == "subdomain" ]]; then
        write_file_if_changed "${NGINX_SITES_AVAILABLE}/${conf_name}.conf" <<< "$conf_content"
        ln -sf "${NGINX_SITES_AVAILABLE}/${conf_name}.conf" "${NGINX_SITES_ENABLED}/${conf_name}.conf"
    else
        mkdir -p "${NGINX_SNIPPETS}/includes"
        write_file_if_changed "${NGINX_SNIPPETS}/includes/phpmyadmin.conf" <<< "$conf_content"
        log_info "Snippet dibuat di ${NGINX_SNIPPETS}/includes/phpmyadmin.conf - tambahkan 'include snippets/includes/phpmyadmin.conf;' pada server block panel/domain yang diinginkan"
    fi

    if nginx -t >>"$INSTALL_LOG_FILE" 2>&1; then
        systemctl reload nginx
        log_ok "Nginx untuk phpMyAdmin aktif"
    else
        log_error "Konfigurasi Nginx phpMyAdmin tidak valid"
        return 1
    fi

    # Persist the resulting URL into the panel's own settings table -
    # previously this was never saved anywhere the panel app could read,
    # so the "URL phpMyAdmin" field on the Pengaturan page stayed blank
    # forever unless an admin noticed and typed it in by hand. Defaults to
    # http:// since SSL for this domain/path isn't necessarily issued yet
    # at this point in the install - editable afterwards on Pengaturan.
    local pma_url
    if [[ "$access_mode" == "subdomain" ]]; then
        pma_url="http://${domain}"
    else
        pma_url="http://${domain}/phpmyadmin"
    fi
    if [[ -n "${PANEL_APP_DB_NAME:-}" ]] && mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
        local escaped_url
        escaped_url=$(mysql_escape "$pma_url")
        if mysql -u root "$PANEL_APP_DB_NAME" -e \
            "INSERT INTO settings (setting_key, setting_value) VALUES ('phpmyadmin_url', '${escaped_url}')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);" >>"$INSTALL_LOG_FILE" 2>&1; then
            log_ok "URL phpMyAdmin disimpan ke Pengaturan panel: ${pma_url}"
        else
            log_warn "Gagal menyimpan URL phpMyAdmin ke database - isi manual di menu Pengaturan panel"
        fi
    fi

    if [[ "$access_mode" == "path" ]]; then
        log_warn "Mode 'path' butuh langkah manual: tambahkan 'include snippets/includes/phpmyadmin.conf;' ke server block yang diinginkan (mis. vhost panel), lalu 'sudo nginx -t && sudo systemctl reload nginx' - tanpa ini phpMyAdmin belum benar-benar bisa diakses."
    fi

    state_mark "phpmyadmin:nginx_generated"
}

module_phpmyadmin_run_all() {
    module_phpmyadmin_download || return 0
    module_phpmyadmin_configure
}
