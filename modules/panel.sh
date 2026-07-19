#!/usr/bin/env bash
# ==============================================================================
# panel.sh - Deploy the web management panel: PHP-FPM pool, .env, sudoers
#             privilege bridge, database admin account, Nginx vhost
# ==============================================================================

PANEL_ROOT="/opt/server-panel"
PANEL_POOL_SOCK="/run/php/panel.sock"

module_panel_deploy_files() {
    log_step "Deploy source panel ke ${PANEL_ROOT}"

    mkdir -p "$PANEL_ROOT"

    # Copy everything except storage/ (never overwrite existing runtime data/logs)
    # and .env (never overwrite existing secrets) on re-runs.
    rsync -a --exclude 'storage/' --exclude '.env' "${SCRIPT_DIR}/panel-src/" "${PANEL_ROOT}/" \
        2>>"$INSTALL_LOG_FILE" || cp -a "${SCRIPT_DIR}/panel-src/." "${PANEL_ROOT}/"

    mkdir -p "${PANEL_ROOT}/storage/logs" "${PANEL_ROOT}/storage/backups" "${PANEL_ROOT}/storage/sessions"

    chown -R panel:panel "$PANEL_ROOT"
    find "$PANEL_ROOT" -type d -exec chmod 750 {} \;
    find "$PANEL_ROOT" -type f -exec chmod 640 {} \;
    chmod 750 "${PANEL_ROOT}/scripts/panel-exec.sh" 2>/dev/null || true

    log_ok "File panel di-deploy ke ${PANEL_ROOT}"
    state_mark "panel:deployed"
}

module_panel_configure_fpm_pool() {
    log_step "Konfigurasi PHP-FPM pool khusus panel (PHP ${PHP_DEFAULT_VERSION})"

    local pool_file="/etc/php/${PHP_DEFAULT_VERSION}/fpm/pool.d/panel.conf"

    write_file_if_changed "$pool_file" <<EOF
[panel]
user = panel
group = panel
listen = ${PANEL_POOL_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500

; /proc is included read-only so SystemService can read CPU/RAM/uptime
; stats (/proc/stat, /proc/meminfo, /proc/uptime) directly - these are
; virtual, world-readable kernel counters, not real user files. Disk usage
; and every other privileged/system operation still goes exclusively
; through panel-exec.sh via sudo (see app/services/Executor.php).
php_admin_value[open_basedir] = ${PANEL_ROOT}:/tmp:/proc
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,popen,pcntl_exec
php_admin_flag[allow_url_fopen] = off
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
php_admin_value[session.save_path] = ${PANEL_ROOT}/storage/sessions
php_admin_value[error_log] = ${PANEL_ROOT}/storage/logs/php-fpm-error.log
php_admin_flag[log_errors] = on
EOF

    systemctl restart "php${PHP_DEFAULT_VERSION}-fpm"
    log_ok "Pool 'panel' aktif pada php${PHP_DEFAULT_VERSION}-fpm (${PANEL_POOL_SOCK})"
    state_mark "panel:fpm_pool"
}

module_panel_write_env() {
    log_step "Menulis file konfigurasi .env panel"

    local env_file="${PANEL_ROOT}/.env"

    if [[ -f "$env_file" ]]; then
        log_ok ".env sudah ada, konfigurasi sebelumnya dipertahankan"
        state_mark "panel:env_written"
        return 0
    fi

    local app_key
    app_key=$(openssl rand -hex 32)

    cat > "$env_file" <<EOF
APP_NAME="Yuuka Server Panel"
APP_ENV=production
APP_KEY=${app_key}
APP_URL=https://${PANEL_DOMAIN}
APP_DEPLOYMENT_MODE=${PANEL_DEPLOYMENT_MODE:-direct}

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${PANEL_APP_DB_NAME}
DB_USERNAME=${PANEL_APP_DB_USER}
DB_PASSWORD=${PANEL_APP_DB_PASS}

DB_PROVISIONER_USERNAME=${PANEL_DB_PROVISIONER_USER}
DB_PROVISIONER_PASSWORD=${PANEL_DB_PROVISIONER_PASS}

SESSION_LIFETIME=1800
SESSION_IDLE_TIMEOUT=900
SESSION_SECURE_COOKIE=1

PANEL_EXEC_SCRIPT=${PANEL_ROOT}/scripts/panel-exec.sh
NODEAPPS_HOME=/home/nodeapps
NGINX_SITES_AVAILABLE=/etc/nginx/sites-available
NGINX_SITES_ENABLED=/etc/nginx/sites-enabled
ACME_WEBROOT=/var/www/_letsencrypt

LOG_PATH=${PANEL_ROOT}/storage/logs
BACKUP_PATH=${PANEL_ROOT}/storage/backups
EOF

    chown panel:panel "$env_file"
    chmod 600 "$env_file"

    log_ok ".env dibuat dengan permission 600 (owner: panel)"
    state_mark "panel:env_written"
}

module_panel_setup_sudoers() {
    log_step "Konfigurasi jembatan privilese (sudoers) untuk panel-exec.sh"

    local exec_script="${PANEL_ROOT}/scripts/panel-exec.sh"
    chown root:root "$exec_script"
    chmod 700 "$exec_script"

    local sudoers_file="/etc/sudoers.d/panel-exec"
    local sudoers_content="panel ALL=(root) NOPASSWD: ${exec_script}
Defaults!${exec_script} !requiretty
Defaults!${exec_script} secure_path=\"/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\""

    local tmp_sudoers
    tmp_sudoers=$(mktemp)
    echo "$sudoers_content" > "$tmp_sudoers"

    if visudo -c -f "$tmp_sudoers" >>"$INSTALL_LOG_FILE" 2>&1; then
        if [[ -f "$sudoers_file" ]] && cmp -s "$tmp_sudoers" "$sudoers_file"; then
            log_ok "sudoers rule sudah sesuai"
        else
            backup_path "$sudoers_file"
            cp "$tmp_sudoers" "$sudoers_file"
            chmod 440 "$sudoers_file"
            log_ok "sudoers rule dipasang: user 'panel' hanya boleh menjalankan ${exec_script} sebagai root, tanpa password"
        fi
    else
        die "sudoers rule tidak valid, instalasi dibatalkan demi keamanan. Cek log: $INSTALL_LOG_FILE"
    fi
    rm -f "$tmp_sudoers"

    state_mark "panel:sudoers"
}

module_panel_create_admin() {
    log_step "Membuat akun administrator pertama"

    if mysql -u root "$PANEL_APP_DB_NAME" -N -e "SELECT COUNT(*) FROM panel_users;" 2>/dev/null | grep -qv '^0$'; then
        log_ok "Sudah ada user panel terdaftar, lewati pembuatan admin"
        state_mark "panel:admin_created"
        return 0
    fi

    ask PANEL_ADMIN_USERNAME "Username administrator panel" "admin"
    ask PANEL_ADMIN_EMAIL_LOCAL "Email administrator" "$PANEL_ADMIN_EMAIL"
    ask_secret PANEL_ADMIN_PASSWORD "Password administrator (min 8 karakter)"

    local hash
    hash=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$PANEL_ADMIN_PASSWORD")

    local esc_user esc_email esc_hash
    esc_user=$(mysql_escape "$PANEL_ADMIN_USERNAME")
    esc_email=$(mysql_escape "$PANEL_ADMIN_EMAIL_LOCAL")
    esc_hash=$(mysql_escape "$hash")

    mysql -u root "$PANEL_APP_DB_NAME" -e \
        "INSERT INTO panel_users (username, email, password_hash, role, is_active, created_at) VALUES ('${esc_user}', '${esc_email}', '${esc_hash}', 'admin', 1, NOW());" \
        >>"$INSTALL_LOG_FILE" 2>&1 || die "Gagal membuat akun administrator"

    log_ok "Akun administrator '${PANEL_ADMIN_USERNAME}' dibuat"
    state_mark "panel:admin_created"
}

mysql_escape() {
    printf '%s' "$1" | sed "s/'/''/g; s/\\\\/\\\\\\\\/g"
}

module_panel_nginx_vhost() {
    log_step "Generate konfigurasi Nginx untuk Panel (${PANEL_DOMAIN})"

    local conf_file="${NGINX_SITES_AVAILABLE}/panel-${PANEL_DOMAIN}.conf"

    write_file_if_changed "$conf_file" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_DOMAIN};

    include ${NGINX_SNIPPETS}/acme-challenge.conf;
    include ${NGINX_SNIPPETS}/cloudflare-realip.conf;

    root ${PANEL_ROOT}/public;
    index index.php;

    access_log /var/log/nginx/panel-${PANEL_DOMAIN}-access.log;
    error_log  /var/log/nginx/panel-${PANEL_DOMAIN}-error.log;

    client_max_body_size 32m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PANEL_POOL_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known) { deny all; }
    location ~* ^/(app|storage|scripts)/ { deny all; }

    include ${NGINX_SNIPPETS}/security-headers.conf;
}
EOF

    ln -sf "$conf_file" "${NGINX_SITES_ENABLED}/panel-${PANEL_DOMAIN}.conf"

    if nginx -t >>"$INSTALL_LOG_FILE" 2>&1; then
        systemctl reload nginx
        log_ok "Nginx panel aktif untuk http://${PANEL_DOMAIN}"
    else
        die "Konfigurasi Nginx panel tidak valid. Cek: $INSTALL_LOG_FILE"
    fi

    state_mark "panel:nginx_vhost"
}

module_panel_logrotate() {
    log_step "Konfigurasi logrotate untuk log panel"

    write_file_if_changed "/etc/logrotate.d/server-panel" <<EOF
${PANEL_ROOT}/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 0640 panel panel
}

/var/log/yuuka-installer/deployment.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0640 root adm
}
EOF
    log_ok "logrotate dikonfigurasi (retensi 14 hari)"
    state_mark "panel:logrotate"
}

module_panel_write_settings() {
    log_step "Menyimpan daftar versi PHP terinstall ke database panel"

    # PhpService (panel side) reads this from the settings table rather than
    # probing /etc/php directly, because the panel PHP-FPM pool's
    # open_basedir is intentionally locked to ${PANEL_ROOT}:/tmp only.
    local versions_csv
    versions_csv=$(IFS=,; echo "${PHP_INSTALLED_VERSIONS[*]}")

    mysql -u root "$PANEL_APP_DB_NAME" -e \
        "INSERT INTO settings (setting_key, setting_value) VALUES ('php_installed_versions', '${versions_csv}'), ('php_default_version', '${PHP_DEFAULT_VERSION}')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);" \
        >>"$INSTALL_LOG_FILE" 2>&1 || log_warn "Gagal menyimpan daftar versi PHP ke database panel"

    log_ok "Versi PHP terdaftar di panel: ${versions_csv}"
    state_mark "panel:settings_written"
}

module_panel_health_check_cron() {
    log_step "Menjadwalkan health check runner (setiap menit)"

    write_file_if_changed "/etc/cron.d/panel-health-check" <<EOF
* * * * * panel /usr/bin/php${PHP_DEFAULT_VERSION} ${PANEL_ROOT}/scripts/health_check_runner.php >/dev/null 2>&1
EOF
    log_ok "Health check runner terjadwal via /etc/cron.d/panel-health-check"
    state_mark "panel:health_check_cron"
}

module_panel_run_all() {
    module_panel_deploy_files
    module_panel_configure_fpm_pool
    module_panel_write_env
    module_panel_setup_sudoers
    module_panel_create_admin
    module_panel_write_settings
    module_panel_nginx_vhost
    module_panel_health_check_cron
    module_panel_logrotate
}
