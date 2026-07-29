#!/usr/bin/env bash
# ==============================================================================
# terminal.sh - Real interactive terminal inside the panel, confined to
#                /var/www/* and /home/nodeapps/apps/* - not by filtering
#                command text (which is fundamentally bypassable: shell
#                escaping, $(), symlinks, etc), but by real Linux kernel
#                mechanisms:
#
#                  - bubblewrap (bwrap) creates a new mount namespace where
#                    only explicitly-bound paths exist AT ALL - everything
#                    else is genuinely absent, not just permission-denied.
#                  - POSIX ACLs (setfacl) grant the dedicated 'panelterm'
#                    user real read-write on the two allowed trees,
#                    additively, without touching www-data's/nodeapps's own
#                    existing ownership/permission bits at all.
#
#                Served by ttyd (WebSocket terminal daemon, xterm.js
#                frontend bundled) bound to 127.0.0.1 only - never public
#                directly. Reachable only through the panel's own Nginx
#                vhost, gated by auth_request against the panel's existing
#                PHP session + RBAC (admin only) - ttyd itself has no idea
#                the panel login even exists.
# ==============================================================================

TERMINAL_USER="panelterm"
TERMINAL_HOME="/home/panelterm"
TERMINAL_WWW_BASE="/var/www"
TERMINAL_NODEAPPS_HOME="/home/nodeapps"
TERMINAL_NODEAPPS_BASE="/home/nodeapps/apps"
TERMINAL_PORT="7682"
TERMINAL_SYSTEMD_UNIT="panelterm-ttyd"

# ---------------------------------------------------------------------------
# Ubuntu 24.04+ restricts unprivileged user namespaces (CLONE_NEWUSER) via
# AppArmor by default - plain `bwrap` fails outright ("Permission denied"
# during uid map setup) unless something grants an exception. This used to
# just flip the `kernel.apparmor_restrict_unprivileged_userns` sysctl to 0
# system-wide - functional, but that lifts the kernel's protection for
# EVERY unprivileged process on the box, not just bwrap.
#
# Scoped instead: a small AppArmor profile attached directly to the bwrap
# BINARY PATH (AppArmor confines by executable, not by caller/user), using
# the `flags=(unconfined) { userns, }` idiom - grants exactly the userns
# mediation bit to processes executing that one binary, `unconfined`
# otherwise so it doesn't ALSO need bwrap's own extensive file-access
# ruleset hand-written here. This is the same pattern Ubuntu itself ships
# for other unprivileged-userns tools (LXC, Chromium's sandbox, distrobox,
# flatpak) - every OTHER unconfined/unprivileged process on the system
# keeps the kernel restriction, only bwrap is exempted. Written as a plain
# file directly under /etc/apparmor.d/ (not relying on Ubuntu's own
# `bwrap-userns-restrict` profile template, which was confirmed on a real
# 24.04 test server to not reliably exist there across point
# releases/apparmor package versions) so it's loaded automatically by the
# system's normal apparmor boot service on every reboot, no separate
# persistence mechanism needed.
#
# Runs AFTER module_terminal_install_packages (not before) so
# `command -v bwrap` resolves the real installed path instead of guessing.
# ---------------------------------------------------------------------------
module_terminal_check_userns() {
    log_step "Memeriksa dukungan user namespace untuk bubblewrap"

    if [[ ! -f /proc/sys/kernel/apparmor_restrict_unprivileged_userns ]]; then
        log_info "Kernel ini tidak mengenal restriksi unprivileged user namespace - lanjut tanpa pengecualian AppArmor"
        state_mark "terminal:userns_checked"
        return 0
    fi

    log_info "Kernel membatasi unprivileged user namespace lewat AppArmor - membuat pengecualian khusus untuk bwrap (bukan mematikan proteksinya untuk seluruh sistem)"

    # Clean up a global sysctl override an older version of this script may
    # have left behind on this server - the scoped AppArmor profile below
    # supersedes it, so every OTHER process on the system gets the kernel's
    # protection back.
    if [[ -f /etc/sysctl.d/99-yuuka-terminal-userns.conf ]]; then
        rm -f /etc/sysctl.d/99-yuuka-terminal-userns.conf
        sysctl -w kernel.apparmor_restrict_unprivileged_userns=1 >>"$INSTALL_LOG_FILE" 2>&1 || true
        log_info "Override sysctl global lama dihapus - digantikan profil AppArmor khusus bwrap"
    fi

    local bwrap_bin
    bwrap_bin=$(command -v bwrap 2>/dev/null || echo /usr/bin/bwrap)

    local profile_file="/etc/apparmor.d/yuuka-panelterm-bwrap"
    write_file_if_changed "$profile_file" <<EOF
${bwrap_bin} flags=(unconfined) {
  userns,
}
EOF

    if command_exists apparmor_parser; then
        if apparmor_parser -r "$profile_file" >>"$INSTALL_LOG_FILE" 2>&1; then
            log_ok "Profil AppArmor untuk bwrap dimuat - user namespace diizinkan khusus untuk ${bwrap_bin}, restriksi tetap aktif untuk proses lain"
        else
            log_warn "Gagal memuat profil AppArmor untuk bwrap - Terminal mungkin tidak berfungsi. Cek: $INSTALL_LOG_FILE"
        fi
    else
        log_warn "apparmor_parser tidak ditemukan - profil ditulis ke ${profile_file} tapi belum dimuat"
    fi

    state_mark "terminal:userns_checked"
}

module_terminal_install_packages() {
    log_step "Install ttyd, bubblewrap, acl"

    apt_install ttyd bubblewrap acl

    if ! command_exists ttyd || ! command_exists bwrap || ! command_exists setfacl; then
        log_error "Gagal menginstall ttyd/bubblewrap/acl - Terminal di Panel tidak akan berfungsi"
        return 1
    fi

    # A setuid-root bwrap would be a system-wide privilege-escalation
    # surface for EVERY local account (panel, nodeapps, www-data), not just
    # this feature - modern Ubuntu (20.04/22.04/24.04, all supporting
    # unprivileged user namespaces) should never package it setuid, but
    # this is cheap to assert rather than assume.
    local bwrap_path bwrap_perm bwrap_owner
    bwrap_path=$(command -v bwrap)
    read -r bwrap_perm bwrap_owner < <(stat -c '%a %U' "$bwrap_path")
    if [[ "$bwrap_owner" == "root" && "$bwrap_perm" == 4* ]]; then
        log_error "bwrap terpasang sebagai setuid-root (mode ${bwrap_perm}) - ini celah privilege escalation SISTEM, bukan cuma fitur Terminal. Setup dihentikan. Cek 'apt policy bubblewrap'."
        return 1
    fi
    log_ok "bwrap tidak setuid-root (mode ${bwrap_perm})"

    # Functional smoke test - a no-op sandboxed command. AppArmor/userns
    # restrictions (see module_terminal_check_userns above) fail exactly
    # here, not later when an admin actually tries to use the terminal -
    # catching it now with a clear message beats a silently broken feature.
    # /lib + /lib64 must be bound too, matching the real systemd unit's
    # bind set - without them the dynamic linker itself
    # (/lib64/ld-linux-x86-64.so.2) is unreachable inside the sandbox, and
    # execve() fails with a misleading "No such file or directory" on the
    # binary being run (confirmed on a real test server) even though
    # namespace creation itself succeeded fine.
    if bwrap --unshare-all --die-with-parent \
        --ro-bind /usr /usr --ro-bind /bin /bin --ro-bind /lib /lib --ro-bind-try /lib64 /lib64 \
        --proc /proc --dev /dev \
        /usr/bin/true >>"$INSTALL_LOG_FILE" 2>&1; then
        log_ok "bwrap bisa membuat sandbox namespace (smoke test lolos)"
    else
        log_error "bwrap GAGAL membuat sandbox namespace - kemungkinan AppArmor/userns dibatasi kernel. Terminal di Panel TIDAK akan berfungsi sampai ini diperbaiki manual di server (cek 'dmesg | tail' setelah mencoba lagi)."
        return 1
    fi

    state_mark "terminal:packages_installed"
}

module_terminal_create_user() {
    log_step "Membuat user sistem '${TERMINAL_USER}'"

    if id "$TERMINAL_USER" &>/dev/null; then
        log_ok "User sistem '${TERMINAL_USER}' sudah ada"
    else
        useradd --system --create-home --home-dir "$TERMINAL_HOME" --shell /usr/sbin/nologin "$TERMINAL_USER"
        log_ok "User sistem '${TERMINAL_USER}' dibuat (tanpa login sendiri, tanpa sudoers)"
    fi

    state_mark "terminal:user_created"
}

# Grants panelterm real read-write on the two trees ADDITIVELY (ACLs never
# touch the existing www-data:www-data / nodeapps:nodeapps ownership or
# 750/640 mode bits - see panel-exec.sh's fm_owner_for_scope) - a bind
# mount inside bwrap does NOT bypass the underlying permission check, so
# without this panelterm could see the paths but not actually write them.
module_terminal_apply_acls() {
    log_step "Menerapkan ACL baca-tulis untuk ${TERMINAL_USER}"

    mkdir -p "$TERMINAL_WWW_BASE" "$TERMINAL_NODEAPPS_BASE"

    setfacl -R -m "u:${TERMINAL_USER}:rwX" -d -m "u:${TERMINAL_USER}:rwX" "$TERMINAL_WWW_BASE" \
        || log_warn "Gagal menerapkan ACL ke ${TERMINAL_WWW_BASE}"
    setfacl -R -m "u:${TERMINAL_USER}:rwX" -d -m "u:${TERMINAL_USER}:rwX" "$TERMINAL_NODEAPPS_BASE" \
        || log_warn "Gagal menerapkan ACL ke ${TERMINAL_NODEAPPS_BASE}"

    # Execute-only (traverse, no read/write) on the PARENT of the node apps
    # tree - an ACL on a subdirectory is useless if the parent itself
    # blocks entering it. /home/nodeapps is only ever created via
    # `useradd --create-home` (modules/system.sh) - never chmod'd
    # explicitly anywhere in this codebase - so this is not redundant.
    setfacl -m "u:${TERMINAL_USER}:--x" "$TERMINAL_NODEAPPS_HOME" \
        || log_warn "Gagal menerapkan ACL traverse ke ${TERMINAL_NODEAPPS_HOME}"

    # Verifies the grant actually works end-to-end, reproducing
    # op_fs_mkdir_website's EXACT sequence (panel-exec.sh) rather than a
    # simplified approximation: mkdir "$dir/public" -> chown -R -> chmod
    # 750 "$dir" (deliberately NOT recursive - only the top site folder
    # itself, not "public/" inside it). That non-recursive chmod resets
    # the ACL mask on "$dir" alone, which would effectively strip
    # panelterm's write bit there specifically - but every real website's
    # actual content lives under "$dir/public/", which never gets that
    # chmod and keeps the full inherited default ACL. Testing inside
    # public/ (not the bare top dir) is what makes this an accurate
    # check instead of a misleading false-negative.
    local test_dir="${TERMINAL_WWW_BASE}/.terminal-acl-test-$$"
    mkdir -p "${test_dir}/public"
    chown -R www-data:www-data "$test_dir"
    chmod 750 "$test_dir"
    local test_file="${test_dir}/public/probe"
    if runuser -u www-data -- touch "$test_file" 2>/dev/null \
        && runuser -u "$TERMINAL_USER" -- test -w "$test_file" 2>/dev/null; then
        log_ok "ACL terverifikasi: ${TERMINAL_USER} bisa baca-tulis file milik www-data"
    else
        log_warn "Verifikasi ACL gagal - ${TERMINAL_USER} mungkin belum bisa baca-tulis penuh di ${TERMINAL_WWW_BASE}"
    fi
    rm -rf "$test_dir" 2>/dev/null

    state_mark "terminal:acls_applied"
}

# Writes+enables the systemd unit that runs `ttyd` (bound to 127.0.0.1
# only) which in turn runs `bwrap` (not a plain shell) which in turn runs
# `bash` - three layers, each doing one job. Deliberately does NOT pass
# bwrap's `--new-session`: that flag's only purpose is preventing
# CVE-2017-5226 (TIOCSTI keystroke injection via a controlling terminal
# SHARED with something outside the sandbox), which cannot happen here -
# the pty is a fresh one ttyd allocates purely for this one WebSocket
# connection, never touched by anything outside the sandbox. Including it
# anyway would cost real functionality (setsid() breaks Ctrl+C/Ctrl+Z/fg/bg
# job control in the sandboxed shell) for a threat that doesn't apply.
#
# `-a` (--url-arg) lets the connecting client pass an initial directory
# via ?arg=<path> (used by File Manager's "Open in Terminal" to land the
# session in the folder that was right-clicked, instead of always
# /var/www) - this is NOT a new privilege boundary: the arg only ever
# reaches a plain `cd` inside the ALREADY-sandboxed bash, so at most it
# can move the cwd to somewhere else bwrap already bind-mounted (still
# just ${TERMINAL_WWW_BASE}/${TERMINAL_NODEAPPS_BASE}) - a bogus or
# malicious path simply fails `cd` silently and falls through to the
# --chdir default, never anything outside the sandbox. Only reachable at
# all through the same auth_request-gated /terminal/ Nginx location every
# other Terminal session already goes through.
#
# .nvm is bind-mounted READ-ONLY so `node`/`npm`/`npx` are actually usable
# inside the sandbox (e.g. `npm install` for a Node app) - it otherwise
# lives entirely outside /var/www and /home/nodeapps/apps, the only two
# trees this sandbox can normally see at all. Read-only is deliberate:
# nothing run in here should be able to install/remove Node VERSIONS
# system-wide (that stays an install/repair-time operation via
# modules/nodejs.sh), only use whatever's already installed. `pm2` itself
# is ALSO reachable this way (it lives in the same nvm-managed bin dir),
# but running it here talks to a SEPARATE daemon under panelterm's own
# $HOME (/var/www, not nodeapps' $HOME) - it will not see or control the
# real, panel-managed PM2 processes at all. Managing those stays through
# the panel's own Start/Stop/Restart buttons and Settings pages, which
# always run as the real 'nodeapps' user via panel-exec.sh.
# NPM_CONFIG_CACHE points at the already-provisioned --tmpfs /tmp instead
# of npm's default $HOME/.npm, so a stray cache dir doesn't get created
# under /var/www (this sandbox's $HOME) on every use.
module_terminal_systemd_unit() {
    log_step "Konfigurasi service ttyd (Terminal di Panel)"

    local unit_file="/etc/systemd/system/${TERMINAL_SYSTEMD_UNIT}.service"
    local bwrap_bin ttyd_bin
    bwrap_bin=$(command -v bwrap)
    ttyd_bin=$(command -v ttyd)

    write_file_if_changed "$unit_file" <<EOF
[Unit]
Description=Yuuka Panel - Terminal (ttyd + bwrap, dibatasi ke ${TERMINAL_WWW_BASE} dan ${TERMINAL_NODEAPPS_BASE})
After=network.target

[Service]
Type=simple
User=${TERMINAL_USER}
Group=${TERMINAL_USER}
ExecStart=${ttyd_bin} -i 127.0.0.1 -p ${TERMINAL_PORT} -b /terminal -W -O -a ${bwrap_bin} \\
    --ro-bind /usr /usr --ro-bind /bin /bin --ro-bind /lib /lib \\
    --ro-bind-try /lib64 /lib64 --ro-bind /etc /etc \\
    --ro-bind-try ${TERMINAL_NODEAPPS_HOME}/.nvm ${TERMINAL_NODEAPPS_HOME}/.nvm \\
    --bind ${TERMINAL_WWW_BASE} ${TERMINAL_WWW_BASE} --bind ${TERMINAL_NODEAPPS_BASE} ${TERMINAL_NODEAPPS_BASE} \\
    --proc /proc --dev /dev --tmpfs /tmp --chdir ${TERMINAL_WWW_BASE} \\
    --unshare-all --share-net --die-with-parent \\
    --clearenv --setenv HOME ${TERMINAL_WWW_BASE} --setenv PATH /usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin --setenv TERM xterm-256color --setenv LANG C.UTF-8 \\
    --setenv NVM_DIR ${TERMINAL_NODEAPPS_HOME}/.nvm --setenv NPM_CONFIG_CACHE /tmp/npm-cache \\
    bash -c 'if [ -n "\$1" ]; then cd -- "\$1" 2>/dev/null || true; fi; [ -s "\$NVM_DIR/nvm.sh" ] && . "\$NVM_DIR/nvm.sh"; exec bash' bash
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    service_enable_now "$TERMINAL_SYSTEMD_UNIT"

    state_mark "terminal:systemd_configured"
}

# Writes the Nginx snippet only - does NOT touch the panel's own vhost
# file here (module_panel_nginx_vhost in modules/panel.sh does that, via
# its own $terminal_include check, exactly mirroring how phpMyAdmin's
# path-mode snippet is picked up). Must run BEFORE the panel vhost is
# (re)generated so the include actually finds this file on a fresh
# install - see install.sh's section ordering.
module_terminal_generate_nginx() {
    log_step "Generate konfigurasi Nginx untuk Terminal di Panel"

    mkdir -p "${NGINX_SNIPPETS}/includes"
    write_file_if_changed "${NGINX_SNIPPETS}/includes/terminal.conf" <<EOF
location = /internal/terminal_auth.php {
    internal;
    fastcgi_pass_request_body off;
    fastcgi_param CONTENT_LENGTH "";
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PANEL_POOL_SOCK};
    fastcgi_param SCRIPT_FILENAME ${PANEL_ROOT}/public/internal/terminal_auth.php;
}

location ^~ /terminal/ {
    # A direct top-level browser navigation to this raw ttyd endpoint
    # (bookmarked, typed manually, opened via right-click "open frame in
    # new tab") would otherwise render with NO panel chrome at all - just
    # the bare terminal, no sidebar/header, no way back except the
    # browser's own back button. Sec-Fetch-Dest distinguishes a real
    # top-level navigation ('document') from this same URL being loaded
    # inside terminal.php's own iframe ('iframe') - a sub-resource fetch/
    # WebSocket upgrade from an ALREADY-loaded terminal page is never
    # tagged 'document' either, so this only redirects the exact case
    # being worked around here, never breaks the terminal once it's
    # actually embedded. Deliberately a plain Nginx-level redirect, not a
    # script injected into ttyd's own response (sub_filter rewriting
    # ttyd's page + patching window.onbeforeunload via
    # Object.defineProperty was tried here first - it sent the terminal
    # into an unrecoverable auto-refresh loop on a real deployment,
    # apparently confusing ttyd's own reconnect logic, which reads/writes
    # that same property. Reverted - this redirect never touches ttyd's
    # page content or JS at all, so it can't interfere with it).
    if (\$http_sec_fetch_dest = document) {
        return 302 /terminal;
    }
    auth_request /internal/terminal_auth.php;
    include ${NGINX_SNIPPETS}/proxy-params.conf;
    # No URI part after the host:port (deliberately no trailing slash) -
    # this makes nginx forward the ORIGINAL request URI unchanged
    # (including the /terminal/ prefix). ttyd is started with -b /terminal
    # (see module_terminal_systemd_unit) specifically because it needs to
    # see that same prefix to generate correct asset/WebSocket URLs - a
    # trailing slash here would strip the prefix before forwarding,
    # leaving ttyd unable to match anything and returning a bare 404
    # (confirmed on a real test server).
    proxy_pass http://127.0.0.1:${TERMINAL_PORT};
}
EOF

    log_ok "Snippet Nginx Terminal dibuat di ${NGINX_SNIPPETS}/includes/terminal.conf"

    # Reloading here picks up CONTENT changes to this snippet on a re-run
    # (e.g. this fix) as long as the panel vhost already includes it - it
    # does NOT solve the very-first-time chicken-and-egg (the panel vhost
    # itself only gains the `include` line the next time it's
    # regenerated), hence the log_warn below still applies for a truly
    # fresh setup.
    if nginx -t >>"$INSTALL_LOG_FILE" 2>&1; then
        systemctl reload nginx
    fi
    log_warn "Kalau ini pertama kali setup Terminal, jalankan 'sudo yp repair panel' sekali lagi setelah ini supaya vhost panel meng-include Terminal (sama seperti phpMyAdmin mode path)."

    state_mark "terminal:nginx_generated"
}

module_terminal_run_all() {
    module_terminal_install_packages || return 0
    module_terminal_check_userns
    module_terminal_create_user
    module_terminal_apply_acls
    module_terminal_systemd_unit
    module_terminal_generate_nginx
}
