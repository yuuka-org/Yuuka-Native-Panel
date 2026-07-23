#!/usr/bin/env bash
# ==============================================================================
# phpmyadmin.sh - Install phpMyAdmin manually (no Apache dependency) served by
#                  Nginx + PHP-FPM, accessible via /phpmyadmin or a subdomain.
# ==============================================================================

PHPMYADMIN_VERSION="${PHPMYADMIN_VERSION:-5.2.1}"
PHPMYADMIN_ROOT="/usr/share/phpmyadmin"
PHPMYADMIN_TMP_DIR="/var/lib/phpmyadmin/tmp"

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
\$cfg['Servers'][\$i]['auth_type']     = 'cookie';
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

# Registers phpMyAdmin under a chosen PHP-FPM version + access mode.
# This is invoked interactively from install.sh after PHP & panel domain are known.
module_phpmyadmin_generate_nginx() {
    local php_version="$1"      # e.g. 8.3
    local access_mode="$2"      # "path" or "subdomain"
    local domain="$3"           # panel domain (path mode) or full pma subdomain (subdomain mode)

    log_step "Generate konfigurasi Nginx untuk phpMyAdmin (${access_mode})"

    local sock="/run/php/php${php_version}-fpm.sock"
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
location ^~ /phpmyadmin/ {
    alias ${PHPMYADMIN_ROOT}/;
    index index.php;

    location ~ ^/phpmyadmin/(.+\\.php)\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${sock};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
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
