#!/usr/bin/env bash
# ==============================================================================
# nginx.sh - Install & configure Nginx as the sole web server / reverse proxy
# ==============================================================================

NGINX_SITES_AVAILABLE="/etc/nginx/sites-available"
NGINX_SITES_ENABLED="/etc/nginx/sites-enabled"
NGINX_SNIPPETS="/etc/nginx/snippets"
ACME_WEBROOT="/var/www/_letsencrypt"

module_nginx_install() {
    log_step "Install Nginx"

    if command_exists nginx; then
        log_ok "Nginx sudah terinstall"
    else
        apt_install nginx
    fi

    mkdir -p "$NGINX_SITES_AVAILABLE" "$NGINX_SITES_ENABLED" "$NGINX_SNIPPETS" "$ACME_WEBROOT"
    chown -R www-data:www-data "$ACME_WEBROOT"

    # Remove default enabled site symlink only (keep the available file, user might reference it)
    if [[ -L "${NGINX_SITES_ENABLED}/default" ]]; then
        rm -f "${NGINX_SITES_ENABLED}/default"
        log_info "Default site nginx dinonaktifkan"
    fi

    service_enable_now nginx
    state_mark "nginx:installed"
}

module_nginx_snippets() {
    log_step "Menulis snippet konfigurasi Nginx bersama"

    write_file_if_changed "${NGINX_SNIPPETS}/security-headers.conf" <<'EOF'
# Shared security headers - included by generated site configs
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
EOF
    log_ok "snippets/security-headers.conf"

    write_file_if_changed "${NGINX_SNIPPETS}/acme-challenge.conf" <<EOF
# Shared Let's Encrypt HTTP-01 challenge location - included by every server block
location ^~ /.well-known/acme-challenge/ {
    # auth_basic is inherited from the server block regardless of location
    # ordering - the panel vhost can turn it on (Settings > General >
    # BasicAuth), and without this override Let's Encrypt's own renewal
    # requests here (which obviously carry no credentials) would get a 401
    # and cert auto-renewal would silently start failing. Harmless no-op
    # for every other vhost, which never sets auth_basic in the first place.
    auth_basic off;
    root ${ACME_WEBROOT};
    default_type "text/plain";
    try_files \$uri =404;
}
EOF
    log_ok "snippets/acme-challenge.conf"

    write_file_if_changed "${NGINX_SNIPPETS}/proxy-params.conf" <<'EOF'
# Shared reverse-proxy headers for Node.js applications
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_cache_bypass $http_upgrade;
proxy_read_timeout 60s;
proxy_send_timeout 60s;
EOF
    log_ok "snippets/proxy-params.conf"

    # Cloudflare real-IP restoration (only meaningful if traffic comes via Cloudflare)
    write_file_if_changed "${NGINX_SNIPPETS}/cloudflare-realip.conf" <<'EOF'
# Trust Cloudflare edge IPs to restore real visitor IP from CF-Connecting-IP.
# Safe to include even when Cloudflare is not used - it only takes effect
# when the connecting IP actually matches one of the ranges below.
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
set_real_ip_from ::1;
real_ip_header CF-Connecting-IP;
EOF
    log_ok "snippets/cloudflare-realip.conf"

    state_mark "nginx:snippets"
}

module_nginx_hardening() {
    log_step "Menerapkan hardening dasar nginx.conf"

    local conf="/etc/nginx/nginx.conf"
    backup_path "$conf"

    if ! grep -q "server_tokens off" "$conf"; then
        sed -i '/http {/a \\tserver_tokens off;' "$conf"
        log_ok "server_tokens off ditambahkan"
    else
        log_ok "server_tokens off sudah dikonfigurasi"
    fi

    if nginx -t >>"$INSTALL_LOG_FILE" 2>&1; then
        systemctl reload nginx
        log_ok "nginx.conf valid, service di-reload"
    else
        log_error "nginx.conf tidak valid setelah perubahan, mengembalikan backup"
        die "Perbaiki konfigurasi nginx secara manual, backup tersedia di $BACKUP_ROOT"
    fi
    state_mark "nginx:hardened"
}

module_nginx_run_all() {
    module_nginx_install
    module_nginx_snippets
    module_nginx_hardening
}
