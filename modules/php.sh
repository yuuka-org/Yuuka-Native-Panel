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

module_php_ensure_extensions() {
    # Handles the case where PHP_EXTENSIONS grows a new entry (e.g. sqlite3
    # for DbCredentialsStore) after a version was already installed on a
    # server - module_php_run_all's "already installed, lewati" branch used
    # to skip package installation entirely for such versions, so the new
    # extension would silently never arrive no matter how many times
    # 'update.sh'/'yp custom-build php' ran. apt_install() only touches
    # packages that aren't already present, so this is a cheap no-op when
    # nothing is missing.
    local version="$1"
    local missing=()
    local ext pkg
    for ext in "${PHP_EXTENSIONS[@]}"; do
        pkg="php${version}-${ext}"
        apt-cache show "$pkg" >/dev/null 2>&1 || continue
        pkg_installed "$pkg" || missing+=("$pkg")
    done

    [[ ${#missing[@]} -eq 0 ]] && return 0

    log_info "PHP ${version}: extension baru terdeteksi, memasang: ${missing[*]}"
    if apt_install "${missing[@]}"; then
        systemctl restart "php${version}-fpm" 2>/dev/null || true
        log_ok "Extension PHP ${version} dilengkapi: ${missing[*]}"
    else
        log_warn "Gagal memasang sebagian extension PHP ${version}: ${missing[*]}"
    fi
}

# ---------------------------------------------------------------------------
# php2shell - CVE-2026-109618 + CVE-2026-109619 (dirilis 21 Okt 2026,
# CVSS v4 9.2, RCE tanpa autentikasi, detail bocor sehari sebelumnya).
#
#   109618: alokasi memori gagal di tengah parsing request (nama field form
#           raksasa / multipart yang menghabiskan memory_limit) meninggalkan
#           bucket $_POST setengah jadi yang lalu "dibersihkan" sebagai data
#           valid.
#   109619: state dari request sebelumnya di worker yang sama menunjuk ke
#           memori yang sudah di-free - dieksploitasi lewat session upload
#           progress (AKTIF secara default, jalan bahkan tanpa
#           session_start()) dan, khusus 8.5, fatal_error_backtraces.
#
# Server ini hanya PHP-FPM di belakang Nginx (tidak ada mod_php) - jalur
# eksploit 109618 yang sudah didemonstrasikan butuh mod_php (FPM mematikan
# worker saat startup gagal), tapi jalur session/upload-progress 109619
# terkonfirmasi juga di FPM. Satu-satunya perbaikan tuntas: versi upstream
# di PHP2SHELL_FIXED_VERSION, atau paket distro/PPA yang mem-backport
# keduanya (7.4/8.0/8.1 sudah EOL - upstream hanya merilis patch sumber).
#
# Sengaja TIDAK diterapkan: memory_limit=-1 (mitigasi 109618 versi advisory)
# - di server multi-situs, satu skrip bocor memori bisa menghabiskan RAM
# seluruh server, dan jalur yang dimitigasinya sudah tertutup di FPM.
# ---------------------------------------------------------------------------
# -g: php.sh is sourced from INSIDE a function by yp (cmd_custom_build,
# cmd_php_security) - a plain `declare -A` there would be local to that
# function call, not a module-level constant.
declare -gA PHP2SHELL_FIXED_VERSION=([8.2]=8.2.35 [8.3]=8.3.36 [8.4]=8.4.27 [8.5]=8.5.12)
PHP2SHELL_INI_NAME="99-yuuka-php2shell.ini"

# Versi yang php-fpm-nya benar-benar terpasang - dipakai saat dipanggil di
# luar module_php_run_all (yp php-security), di mana PHP_INSTALLED_VERSIONS
# belum terisi.
module_php_detect_installed_versions() {
    local v
    for v in "${PHP_VERSIONS[@]}"; do
        pkg_installed "php${v}-fpm" && echo "$v"
    done
}

# Versi upstream dari paket terpasang, mis. "8.3.36-1+ubuntu24.04.1+deb.sury.org+1"
# -> "8.3.36" (epoch "1:" dan revisi Debian/PPA dibuang).
module_php_upstream_version() {
    local pkg_ver
    pkg_ver=$(dpkg-query -W -f='${Version}' "php${1}-fpm" 2>/dev/null) || return 1
    [[ -n "$pkg_ver" ]] || return 1
    pkg_ver="${pkg_ver#*:}"
    printf '%s' "${pkg_ver%%[-+~]*}"
}

# patched | backport | vulnerable | unknown
#   patched    - versi upstream >= versi perbaikan resmi
#   backport   - changelog paket menyebut kedua CVE (PPA/distro mem-backport
#                tanpa menaikkan versi upstream - satu-satunya jalan untuk
#                7.4/8.0/8.1)
#   vulnerable - versi lebih lama & tidak ada bukti backport
#   unknown    - cabang EOL dan changelog tidak tersedia (image Ubuntu
#                "minimized" membuang /usr/share/doc), jadi tidak bisa
#                dipastikan
module_php_php2shell_state() {
    local version="$1" upstream fixed cl has_changelog=0
    upstream=$(module_php_upstream_version "$version") || { echo unknown; return; }
    fixed="${PHP2SHELL_FIXED_VERSION[$version]:-}"
    if [[ -n "$fixed" ]] && dpkg --compare-versions "$upstream" ge "$fixed"; then
        echo patched; return
    fi
    for cl in "/usr/share/doc/php${version}-fpm/changelog.Debian.gz" "/usr/share/doc/php${version}-common/changelog.Debian.gz"; do
        [[ -f "$cl" ]] || continue
        has_changelog=1
        if zgrep -q 'CVE-2026-109618' "$cl" && zgrep -q 'CVE-2026-109619' "$cl"; then
            echo backport; return
        fi
    done
    if [[ -n "$fixed" || "$has_changelog" == "1" ]]; then
        echo vulnerable
    else
        echo unknown
    fi
}

# Config-level mitigasi yang tetap berguna walau sudah dipatch (defense in
# depth) - conf.d berlaku untuk SEMUA pool versi itu (www/panel/phpmyadmin).
# Harus di config yang dimuat SEBELUM request di-parse: ini_set() dan
# .user.ini aplikasi datang terlambat, jadi juga tidak bisa dipakai situs
# untuk menyalakannya kembali sebelum fase parsing. Fitur yang dimatikan
# hanya progress bar upload berbasis session (jarang dipakai); upload-nya
# sendiri tetap jalan normal. Return 0 kalau file berubah (perlu restart).
module_php_php2shell_harden() {
    local version="$1"
    local conf_dir="/etc/php/${version}/fpm/conf.d"
    [[ -d "$conf_dir" ]] || return 1

    local backtraces=""
    if dpkg --compare-versions "$version" ge 8.5; then
        backtraces=$'\n; Jalur 109619 khusus PHP 8.5 (backtrace fatal error).\nfatal_error_backtraces = 0'
    fi

    write_file_if_changed "${conf_dir}/${PHP2SHELL_INI_NAME}" <<EOF
; Dikelola oleh modules/php.sh (Yuuka Panel) - jangan diedit manual.
; Mitigasi php2shell: CVE-2026-109618 / CVE-2026-109619.
; Jalur 109619 lewat session upload progress (default aktif, jalan tanpa
; session_start()). Tetap dipasang setelah PHP dipatch sebagai lapisan
; pertahanan tambahan.
session.upload_progress.enabled = 0${backtraces}
EOF
}

# Hanya meng-upgrade paket php<versi>* yang SUDAH terpasang (--only-upgrade
# tidak pernah memasang paket baru), config yang sudah disesuaikan installer
# (php.ini, www.conf) dipertahankan lewat --force-confold.
module_php_php2shell_upgrade() {
    local pkgs=() v pkg
    for v in "$@"; do
        while IFS= read -r pkg; do
            [[ -n "$pkg" ]] && pkgs+=("$pkg")
        done < <(dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' "php${v}*" 2>/dev/null | awk '$1 == "ii" {print $2}')
    done
    [[ ${#pkgs[@]} -gt 0 ]] || return 0

    spinner_run "apt-get update (cek patch PHP terbaru)" -- apt-get update -y || return 1
    spinner_run "Upgrade paket PHP terpasang (${#pkgs[@]} paket)" -- \
        env DEBIAN_FRONTEND=noninteractive apt-get install -y --only-upgrade \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "${pkgs[@]}"
}

# Satu baris status per versi + petunjuk tindakan. Return = jumlah versi
# yang belum aman (vulnerable/unknown) supaya pemanggil bisa memberi
# ringkasan/exit code.
module_php_php2shell_report() {
    local not_safe=0 v state upstream mitig
    for v in "$@"; do
        state=$(module_php_php2shell_state "$v")
        upstream=$(module_php_upstream_version "$v" || echo '?')
        if [[ -f "/etc/php/${v}/fpm/conf.d/${PHP2SHELL_INI_NAME}" ]]; then mitig="mitigasi config aktif"; else mitig="mitigasi config BELUM ada"; fi
        case "$state" in
            patched)  log_ok   "PHP ${v} (${upstream}): sudah dipatch (>= ${PHP2SHELL_FIXED_VERSION[$v]}), ${mitig}" ;;
            backport) log_ok   "PHP ${v} (${upstream}): patch backport terdeteksi di changelog paket, ${mitig}" ;;
            vulnerable)
                not_safe=$((not_safe + 1))
                if [[ -n "${PHP2SHELL_FIXED_VERSION[$v]:-}" ]]; then
                    log_warn "PHP ${v} (${upstream}): MASIH RENTAN - butuh ${PHP2SHELL_FIXED_VERSION[$v]}, belum tersedia di repository. ${mitig}. Coba lagi nanti: sudo yp php-security"
                else
                    log_warn "PHP ${v} (${upstream}): MASIH RENTAN - cabang EOL tanpa backport. ${mitig}. Pindahkan situs ke PHP 8.2+ (Website > Settings > Umum)"
                fi
                ;;
            *)
                not_safe=$((not_safe + 1))
                log_warn "PHP ${v} (${upstream}): status patch TIDAK BISA dipastikan (cabang EOL, changelog paket tidak ada). ${mitig}. Anggap rentan - pindahkan situs ke PHP 8.2+"
                ;;
        esac
    done
    return "$not_safe"
}

# Indikator kompromi dari advisory (log + proses) - informatif saja, tidak
# mengubah apa pun. Kecocokan bukan bukti pasti (OOM/segfault juga terjadi
# di beban normal), jadi ditandai "periksa", bukan "terinfeksi".
module_php_php2shell_ioc_scan() {
    log_step "Memeriksa indikator eksploitasi php2shell (informatif)"
    local hits
    hits=$(grep -h 'Allowed memory size' /var/log/nginx/*error.log /opt/server-panel/storage/logs/*.log 2>/dev/null | grep -c 'Unknown on line 0' || true)
    if [[ "${hits:-0}" -gt 0 ]]; then
        log_warn "${hits} baris 'Allowed memory size ... Unknown on line 0' di log error (kehabisan memori SAAT PARSING request - pola eksploit 109618). Periksa: grep -h 'Unknown on line 0' /var/log/nginx/*error.log"
    else
        log_ok "Tidak ada pola OOM saat parsing request di log error Nginx/panel"
    fi
    hits=$(cat /var/log/php*-fpm.log 2>/dev/null | grep -cE 'SIGSEGV|signal 11' || true)
    if [[ "${hits:-0}" -gt 0 ]]; then
        log_warn "${hits} crash worker PHP-FPM (SIGSEGV) di /var/log/php*-fpm.log - bisa jadi percobaan eksploit yang gagal. Periksa waktunya: grep -h SIGSEGV /var/log/php*-fpm.log"
    else
        log_ok "Tidak ada crash worker PHP-FPM (SIGSEGV)"
    fi
    # Shell yang induknya worker php-fpm = bukti paling langsung menurut
    # advisory. 'sudo' sengaja dikecualikan: panel sendiri memanggil
    # panel-exec.sh lewat sudo dari pool-nya (Executor.php).
    local shells
    shells=$(ps -eo pid=,ppid=,user=,comm= 2>/dev/null | awk '
        { pid[NR] = $1; ppid[NR] = $2; user[NR] = $3; comm[NR] = $4; name[$1] = $4 }
        END {
            for (i = 1; i <= NR; i++)
                if (comm[i] ~ /^(sh|bash|dash|zsh|nc|ncat|socat|perl|python3?)$/ && name[ppid[i]] ~ /^php-fpm/)
                    print "    pid " pid[i] " (" comm[i] ", user " user[i] ") dari php-fpm pid " ppid[i]
        }' || true)
    if [[ -n "$shells" ]]; then
        log_warn "Ada shell/interpreter yang dijalankan langsung oleh worker PHP-FPM - periksa apakah memang wajar untuk situs itu:"
        echo "$shells"
    else
        log_ok "Tidak ada shell yang sedang dijalankan oleh worker PHP-FPM"
    fi
}

# Alur lengkap: upgrade -> hardening -> restart pool yang berubah -> laporan.
module_php_php2shell_mitigate() {
    local versions=("$@")
    [[ ${#versions[@]} -gt 0 ]] || return 0
    log_step "Mitigasi php2shell (CVE-2026-109618, CVE-2026-109619)"

    local -A before=()
    local v
    for v in "${versions[@]}"; do
        before[$v]=$(dpkg-query -W -f='${Version}' "php${v}-fpm" 2>/dev/null || true)
    done

    module_php_php2shell_upgrade "${versions[@]}" || log_warn "Upgrade paket PHP gagal sebagian - mitigasi config tetap diterapkan, cek log di atas"

    for v in "${versions[@]}"; do
        local changed=0 after
        module_php_php2shell_harden "$v" && changed=1
        after=$(dpkg-query -W -f='${Version}' "php${v}-fpm" 2>/dev/null || true)
        [[ "$after" != "${before[$v]}" ]] && changed=1
        # Semua worker WAJIB direstart - state basi (109619) hidup di worker
        # yang sudah jalan, dan ini baru terbaca saat worker start.
        if [[ "$changed" == "1" ]]; then
            if systemctl restart "php${v}-fpm" >>"$INSTALL_LOG_FILE" 2>&1; then
                log_ok "php${v}-fpm direstart (paket/konfigurasi baru aktif)"
            else
                log_error "Gagal restart php${v}-fpm - cek: journalctl -u php${v}-fpm -n 50"
            fi
        fi
    done

    local not_safe=0
    module_php_php2shell_report "${versions[@]}" || not_safe=$?
    if [[ "$not_safe" -gt 0 ]]; then
        log_warn "${not_safe} versi PHP belum aman dari php2shell. Selama itu, aktifkan BasicAuth panel (Settings > Umum atau 'sudo yp basicauth') supaya request tanpa kredensial ditolak Nginx sebelum sempat di-parse PHP."
    fi
    return 0
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
            module_php_ensure_extensions "$v"
            continue
        fi
        module_php_install_version "$v" || true
    done

    if [[ ${#PHP_INSTALLED_VERSIONS[@]} -eq 0 ]]; then
        die "Tidak ada satupun versi PHP yang berhasil diinstall. Periksa koneksi internet / repository."
    fi

    # Every install/update/custom-build run re-checks for the php2shell
    # fix and re-applies the config mitigation - cheap no-op once patched
    # (apt has nothing to upgrade, ini unchanged -> no restart).
    module_php_php2shell_mitigate "${PHP_INSTALLED_VERSIONS[@]}"

    module_php_select_default
    export PHP_INSTALLED_VERSIONS
}
