#!/usr/bin/env bash
# ==============================================================================
# openssl.sh - OpenSSL 3.5.x LTS khusus untuk Nginx, TANPA mengganti OpenSSL
#               sistem.
#
#               Why: Ubuntu tracks OpenSSL 3.0.x for a release's whole life and
#               never gains newer TLS 1.3 key exchange groups. When a client's
#               ClientHello only offers key_share entries for a group 3.0
#               doesn't recognize (observed live in production: Cloudflare's
#               edge connecting to a panel-managed origin, "SSL routines::no
#               suitable key share", OpenSSL error 0A000065), the handshake
#               fails outright - Cloudflare error 525 for visitors, with ZERO
#               trace in Nginx's own logs at the default log level.
#
#               This module used to build 3.5.x straight over the SYSTEM
#               libssl/libcrypto. That locked an operator out of SSH: OpenSSH
#               older than 9.x (Ubuntu 22.04 ships 8.9) refuses a libcrypto
#               whose minor version differs from the one it was built against
#               ("OpenSSL version mismatch"), and sshd re-execs itself for every
#               connection - every new login got reset right after the TCP
#               connect. Only Nginx needs 3.5, so now only Nginx gets it:
#
#               1. OpenSSL 3.5.x goes into a private, versioned prefix
#                  (/opt/yuuka-openssl/<version>) nothing else links to.
#               2. Nginx is rebuilt from UBUNTU'S OWN source package of the
#                  version that is installed (apt-get source: checked against
#                  the signed archive, carries Ubuntu's security patches) with
#                  the distro binary's own configure arguments, linked to that
#                  prefix through rpath. Same version + same --with-compat
#                  arguments keep the distro's libnginx-mod-* dynamic modules
#                  loading, and they resolve libssl.so.3 to the copy Nginx
#                  already has loaded - one process never mixes two OpenSSLs.
#               3. Only /usr/sbin/nginx is replaced, through dpkg-divert (apt
#                  then installs the package's binary as /usr/sbin/nginx.distrib
#                  instead of overwriting ours). The new binary must pass
#                  `nginx -t` against the live config first, and Nginx must come
#                  back after the restart - otherwise the previous binary
#                  returns. An apt hook falls back to the distro binary if a
#                  package upgrade ever moves nginx to another upstream version
#                  (its new modules wouldn't load into our older binary).
#               4. A server still carrying the old system-wide build gets
#                  Ubuntu's packages back (holds lifted), so OpenSSL security
#                  updates flow through apt again and sshd works everywhere.
#
#               Rebuilds by itself (update.sh, or `sudo yp custom-build
#               openssl`) whenever a new 3.5.x point release or a new Ubuntu
#               nginx package version shows up. Opt out on one server:
#               touch /var/lib/yuuka-installer/nginx-openssl.disabled
# ==============================================================================

OPENSSL_MULTIARCH="$(dpkg-architecture -qDEB_HOST_MULTIARCH 2>/dev/null || echo x86_64-linux-gnu)"
OPENSSL_BUILD_DIR="/usr/local/src/panel-openssl-build"
OPENSSL_PRIVATE_ROOT="/opt/yuuka-openssl"
OPENSSL_FALLBACK_TAG="openssl-3.5.7"

NGINX_BIN="/usr/sbin/nginx"
NGINX_DISTRIB_BIN="/usr/sbin/nginx.distrib"
NGINX_DIVERT_PKG="yuuka-panel"
NGINX_OPENSSL_STATE="${STATE_DIR}/nginx-openssl.state"
NGINX_OPENSSL_FAILED="${STATE_DIR}/nginx-openssl.failed"
NGINX_OPENSSL_OPTOUT="${STATE_DIR}/nginx-openssl.disabled"
NGINX_APT_GUARD="/usr/local/sbin/yuuka-nginx-guard"
NGINX_APT_GUARD_CONF="/etc/apt/apt.conf.d/99yuuka-nginx-guard"

# Written by the old design (and the SSH Rescue plugin) to mean "don't rebuild
# the SYSTEM OpenSSL" - meaningless now that the system copy is never touched.
OPENSSL_LEGACY_MARKER="${STATE_DIR}/openssl-module.disabled"

openssl_apt() {
    env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 UCF_FORCE_CONFFOLD=1 \
        apt-get -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
        "$@" </dev/null
}

# Newest openssl-3.5.* release tag on GitHub (the OpenSSL project's own LTS
# track, supported into the early 2030s), or nothing when the API is
# unreachable/rate-limited.
openssl_latest_35_tag() {
    curl -fsSL --max-time 20 "https://api.github.com/repos/openssl/openssl/releases?per_page=100" 2>/dev/null \
        | grep -o '"tag_name": *"openssl-3\.5\.[0-9]*"' \
        | grep -o 'openssl-3\.5\.[0-9]*' \
        | sort -t. -k3 -n \
        | tail -1
}

# Tag to build. Without the API, keep the newest private build already on this
# server rather than "downgrading" to the pinned fallback. Warnings go to
# stderr: callers capture stdout.
openssl_target_tag() {
    local tag
    tag=$(openssl_latest_35_tag)
    if [[ -z "$tag" ]]; then
        tag=$(find "$OPENSSL_PRIVATE_ROOT" -mindepth 1 -maxdepth 1 -type d -name '3.5.*' -printf 'openssl-%f\n' 2>/dev/null \
            | sort -t. -k3 -n | tail -1)
        tag="${tag:-$OPENSSL_FALLBACK_TAG}"
        log_warn "Tidak bisa mendeteksi rilis OpenSSL 3.5.x terbaru dari GitHub API - pakai ${tag}" >&2
    fi
    echo "$tag"
}

openssl_install_missing() {
    # openssl_install_missing "deskripsi" pkg...
    local desc="$1"; shift
    local missing=() pkg
    for pkg in "$@"; do
        pkg_installed "$pkg" || missing+=("$pkg")
    done
    [[ ${#missing[@]} -eq 0 ]] && return 0
    log_info "${desc}: ${missing[*]}"
    if openssl_apt install -y --no-install-recommends "${missing[@]}" >>"$INSTALL_LOG_FILE" 2>&1; then
        log_ok "${desc} terpasang"
        return 0
    fi
    # stale package lists (update.sh doesn't refresh them) - once more, fresh
    openssl_apt update -qq >>"$INSTALL_LOG_FILE" 2>&1
    spinner_run "${desc}: ${missing[*]}" -- openssl_apt install -y --no-install-recommends "${missing[@]}"
}

# Build OpenSSL <version> into <prefix> unless a complete build is already
# there. Returns 2 for download/checksum trouble (worth retrying next run), 1
# for a failed build.
openssl_private_build() {
    local tag="$1" version="$2" prefix="$3"
    if [[ "$("${prefix}/bin/openssl" version 2>/dev/null | awk '{print $2}')" == "$version" ]]; then
        log_ok "OpenSSL ${version} pribadi sudah ada di ${prefix}"
        return 0
    fi

    local work="${OPENSSL_BUILD_DIR}/openssl" tarball="${tag}.tar.gz"
    local base_url="https://github.com/openssl/openssl/releases/download/${tag}"
    mkdir -p "$work"

    log_info "Mengunduh source OpenSSL ${version}"
    if ! curl -fsSL -o "${work}/${tarball}" "${base_url}/${tarball}" \
        || ! curl -fsSL -o "${work}/${tarball}.sha256" "${base_url}/${tarball}.sha256"; then
        log_error "Gagal mengunduh source/checksum OpenSSL ${version}"
        return 2
    fi
    if ! (cd "$work" && sha256sum -c "${tarball}.sha256" >>"$INSTALL_LOG_FILE" 2>&1); then
        log_error "Checksum source OpenSSL ${version} TIDAK COCOK - dibatalkan, file tidak dipercaya"
        return 2
    fi
    log_ok "Checksum source OpenSSL ${version} terverifikasi"
    (cd "$work" && tar xzf "$tarball") || { log_error "Gagal mengekstrak source OpenSSL ${version}"; return 1; }

    # --openssldir=/etc/ssl: same config file and CA store as Ubuntu's own
    # copy (install_sw never writes there). The rpath makes this libssl load
    # THIS libcrypto, never the system one.
    log_info "Build OpenSSL ${version} ke ${prefix} (beberapa menit)"
    rm -rf "$prefix"
    if ! (
        cd "${work}/${tag}" \
            && ./config shared --prefix="$prefix" --libdir=lib --openssldir=/etc/ssl no-tests "-Wl,-rpath,${prefix}/lib" >>"$INSTALL_LOG_FILE" 2>&1 \
            && make -j"$(nproc)" >>"$INSTALL_LOG_FILE" 2>&1 \
            && make install_sw >>"$INSTALL_LOG_FILE" 2>&1
    ) || [[ "$("${prefix}/bin/openssl" version 2>/dev/null | awk '{print $2}')" != "$version" ]]; then
        rm -rf "$prefix"
        log_error "Build OpenSSL ${version} gagal - cek $INSTALL_LOG_FILE"
        return 1
    fi
    log_ok "OpenSSL ${version} terpasang di ${prefix} (hanya dipakai Nginx)"
}

# Ubuntu's source package of the exact installed version, via a throwaway
# deb-src copy of this server's own apt sources - the system's sources, lists
# and caches are never touched. Sets NGINX_SRC_DIR.
nginx_fetch_source() {
    local srcpkg="$1" srcver="$2" dest="$3" aptdir="$3/apt" f
    mkdir -p "$aptdir/parts" "$aptdir/lists/partial" "$aptdir/cache/archives/partial"
    : > "$aptdir/empty.list"
    for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list; do
        [[ -f "$f" ]] && sed -nE 's/^[[:space:]]*deb([[:space:]])/deb-src\1/p' "$f"
    done > "$aptdir/parts/yuuka-deb-src.list"
    for f in /etc/apt/sources.list.d/*.sources; do
        [[ -f "$f" ]] && sed -E 's/^Types:.*/Types: deb-src/' "$f" > "$aptdir/parts/$(basename "$f")"
    done
    local opts=(-o Dir::Etc::SourceList="$aptdir/empty.list" -o Dir::Etc::SourceParts="$aptdir/parts"
        -o Dir::State::Lists="$aptdir/lists" -o Dir::Cache="$aptdir/cache" -o APT::Get::List-Cleanup=0)

    log_info "Mengambil source paket ${srcpkg} ${srcver} dari arsip Ubuntu (apt-get source)"
    # Third-party repos without deb-src only fail their own index here.
    openssl_apt "${opts[@]}" update >>"$INSTALL_LOG_FILE" 2>&1 || true
    (cd "$dest" && openssl_apt "${opts[@]}" source --only-source "${srcpkg}=${srcver}" >>"$INSTALL_LOG_FILE" 2>&1) || return 1
    NGINX_SRC_DIR=$(find "$dest" -mindepth 1 -maxdepth 1 -type d -name "${srcpkg}-*" | head -1)
    [[ -n "$NGINX_SRC_DIR" && -x "${NGINX_SRC_DIR}/configure" ]]
}

# The distro binary's own configure arguments, minus what only concerns the
# dynamic modules (those keep coming from the libnginx-mod-* packages), plus
# the private OpenSSL. Sets NGINX_CONF_ARGS.
nginx_configure_args() {
    local distrib="$1" srcdir="$2" prefix="$3" a cc_opt="" ld_opt=""
    local raw=()
    mapfile -t raw < <("$distrib" -V 2>&1 | sed -n 's/^configure arguments: //p' | xargs -n1 printf '%s\n')
    NGINX_CONF_ARGS=()
    [[ ${#raw[@]} -gt 0 ]] || return 1
    for a in "${raw[@]}"; do
        case "$a" in
            --with-cc-opt=*) cc_opt="${a#--with-cc-opt=}" ;;
            --with-ld-opt=*) ld_opt="${a#--with-ld-opt=}" ;;
            --add-dynamic-module=* | --with-*=dynamic | --with-openssl=* | --with-openssl-opt=*) ;;
            --add-module=*/debian/modules/*) NGINX_CONF_ARGS+=("--add-module=${srcdir}/debian/modules/${a##*/debian/modules/}") ;;
            *) NGINX_CONF_ARGS+=("$a") ;;
        esac
    done
    # nginx builds with -Werror; deprecation notices from the newer headers
    # must not fail an otherwise identical build.
    NGINX_CONF_ARGS+=(
        "--with-cc-opt=${cc_opt} -I${prefix}/include -Wno-error -Wno-deprecated-declarations"
        "--with-ld-opt=${ld_opt} -L${prefix}/lib -Wl,-rpath,${prefix}/lib"
    )
}

# True when <bin> is a panel build loading libssl AND libcrypto from <prefix>.
nginx_runs_private_openssl() {
    local bin="$1" version="$2" prefix="$3"
    "$bin" -V 2>&1 | grep -qF "built with OpenSSL ${version} " || return 1
    [[ $(ldd "$bin" 2>/dev/null | grep -E 'lib(ssl|crypto)\.so\.3 ' | grep -cF "=> ${prefix}/lib/") -eq 2 ]]
}

# Put <newbin> in place of /usr/sbin/nginx and restart; back to the previous
# binary if Nginx doesn't come up with it.
nginx_swap_in() {
    local newbin="$1" prev="" first=0
    if [[ "$(dpkg-divert --truename "$NGINX_BIN")" == "$NGINX_BIN" ]]; then
        first=1
        if ! dpkg-divert --package "$NGINX_DIVERT_PKG" --divert "$NGINX_DISTRIB_BIN" --rename --add "$NGINX_BIN" >>"$INSTALL_LOG_FILE" 2>&1; then
            log_error "dpkg-divert ${NGINX_BIN} gagal"
            return 1
        fi
    else
        prev="${NGINX_BIN}.yuuka-prev"
        cp -a "$NGINX_BIN" "$prev"
    fi

    if install -m 0755 "$newbin" "${NGINX_BIN}.yuuka-new" \
        && mv -f "${NGINX_BIN}.yuuka-new" "$NGINX_BIN" \
        && systemctl restart nginx >>"$INSTALL_LOG_FILE" 2>&1 \
        && sleep 2 && systemctl is-active --quiet nginx; then
        rm -f "$prev"
        return 0
    fi

    log_error "Nginx tidak mau hidup dengan binary baru - binary sebelumnya dikembalikan"
    rm -f "${NGINX_BIN}.yuuka-new"
    if [[ $first -eq 1 ]]; then
        rm -f "$NGINX_BIN"
        dpkg-divert --package "$NGINX_DIVERT_PKG" --rename --remove "$NGINX_BIN" >>"$INSTALL_LOG_FILE" 2>&1
    else
        mv -f "$prev" "$NGINX_BIN"
    fi
    systemctl restart nginx >>"$INSTALL_LOG_FILE" 2>&1 || true
    return 1
}

nginx_install_apt_guard() {
    write_file_if_changed "$NGINX_APT_GUARD" <<'EOF' || true
#!/bin/sh
# Dikelola modules/openssl.sh (Yuuka panel) - jangan diedit manual.
# Dipanggil apt setiap kali dpkg selesai (DPkg::Post-Invoke).
#
# /usr/sbin/nginx adalah build panel (OpenSSL 3.5 pribadi); binary paket
# Ubuntu dialihkan dpkg-divert ke /usr/sbin/nginx.distrib. Kalau paket nginx
# naik ke versi UPSTREAM lain, modul dinamis libnginx-mod-* yang baru tidak
# bisa dimuat binary panel yang lama -> pakai binary paket dulu supaya Nginx
# tetap hidup; update panel berikutnya membangun ulang. Kalau /usr/sbin/nginx
# sudah salinan binary paket, salinannya ikut diperbarui (patch keamanan).
distrib=/usr/sbin/nginx.distrib
bin=/usr/sbin/nginx
[ -x "$distrib" ] || exit 0
ver() { "$1" -v 2>&1 | sed -n 's#^nginx version: nginx/\([0-9.]*\).*#\1#p'; }
if "$bin" -V 2>&1 | grep -q '/opt/yuuka-openssl/'; then
    [ "$(ver "$bin")" = "$(ver "$distrib")" ] && exit 0
    reason="versi upstream paket nginx berubah ($(ver "$bin") -> $(ver "$distrib"))"
else
    cmp -s "$distrib" "$bin" && exit 0
    reason="binary paket nginx diperbarui"
fi
cp -f "$distrib" "$bin.yuuka-new" && mv -f "$bin.yuuka-new" "$bin" || exit 0
logger -t yuuka-nginx-guard "Nginx memakai binary paket Ubuntu: ${reason}. Build ulang Nginx + OpenSSL 3.5: sudo yp custom-build openssl" 2>/dev/null
systemctl restart nginx >/dev/null 2>&1 || true
exit 0
EOF
    chmod 0755 "$NGINX_APT_GUARD"
    write_file_if_changed "$NGINX_APT_GUARD_CONF" <<EOF || true
// Dikelola modules/openssl.sh (Yuuka panel) - jangan diedit manual.
DPkg::Post-Invoke { "if [ -x ${NGINX_APT_GUARD} ]; then ${NGINX_APT_GUARD} || true; fi"; };
EOF
    chmod 0644 "$NGINX_APT_GUARD_CONF"
}

# Everything from the build dependencies to the swap. Returns 2 for transient
# trouble (downloads, apt) worth retrying next run, 1 for a deterministic
# failure that would just repeat.
nginx_openssl_build_and_swap() {
    local distrib="$1" srcpkg="$2" srcver="$3" tag="$4" version="$5" prefix="$6" rc

    local deps=(build-essential perl zlib1g-dev libcrypt-dev) linked
    linked=$(ldd "$distrib" 2>/dev/null)
    grep -q 'libpcre2-8\.so' <<<"$linked" && deps+=(libpcre2-dev)
    grep -q 'libpcre\.so\.3' <<<"$linked" && deps+=(libpcre3-dev)
    openssl_install_missing "Install dependency build Nginx/OpenSSL" "${deps[@]}" || return 2

    rm -rf "$OPENSSL_BUILD_DIR"
    mkdir -p "$OPENSSL_BUILD_DIR"
    openssl_private_build "$tag" "$version" "$prefix" || { rc=$?; return "$rc"; }

    mkdir -p "${OPENSSL_BUILD_DIR}/nginx"
    if ! nginx_fetch_source "$srcpkg" "$srcver" "${OPENSSL_BUILD_DIR}/nginx"; then
        log_error "Gagal mengambil source ${srcpkg} ${srcver} dari arsip Ubuntu (kalau versi ini sudah diganti update keamanan: apt-get update && apt-get upgrade, lalu ulangi)"
        return 2
    fi
    if ! nginx_configure_args "$distrib" "$NGINX_SRC_DIR" "$prefix"; then
        log_error "Tidak bisa membaca argumen configure dari '${distrib} -V'"
        return 1
    fi

    log_info "Build Nginx ${srcver} dengan OpenSSL ${version} (beberapa menit)"
    if ! (cd "$NGINX_SRC_DIR" && ./configure "${NGINX_CONF_ARGS[@]}" >>"$INSTALL_LOG_FILE" 2>&1 && make -j"$(nproc)" >>"$INSTALL_LOG_FILE" 2>&1); then
        log_error "Build Nginx gagal - cek $INSTALL_LOG_FILE"
        return 1
    fi

    local newbin="${NGINX_SRC_DIR}/objs/nginx"
    if ! nginx_runs_private_openssl "$newbin" "$version" "$prefix"; then
        log_error "Binary Nginx baru tidak memuat libssl/libcrypto dari ${prefix}: $(ldd "$newbin" 2>&1 | grep -E 'lib(ssl|crypto)' | tr -s ' \t' ' ')"
        return 1
    fi
    if ! "$newbin" -t >>"$INSTALL_LOG_FILE" 2>&1; then
        log_error "Binary Nginx baru gagal 'nginx -t' dengan konfigurasi yang sedang dipakai - cek $INSTALL_LOG_FILE"
        return 1
    fi
    log_ok "Binary Nginx baru lolos uji (OpenSSL ${version}, nginx -t dengan konfigurasi aktif)"

    nginx_swap_in "$newbin"
}

module_openssl_nginx_build() {
    log_step "Nginx dengan OpenSSL 3.5.x pribadi (OpenSSL sistem tidak diubah)"

    if [[ -f "$NGINX_OPENSSL_OPTOUT" ]]; then
        log_warn "Dinonaktifkan di server ini (${NGINX_OPENSSL_OPTOUT}) - Nginx memakai binary paketnya sendiri"
        return 0
    fi

    local distrib owner srcpkg="" srcver="" tag version prefix want
    distrib=$(dpkg-divert --truename "$NGINX_BIN" 2>/dev/null)
    distrib="${distrib:-$NGINX_BIN}"
    owner=$(dpkg-query -S "$NGINX_BIN" 2>/dev/null | awk -F': ' '$1 !~ /^diversion/ {print $1; exit}')
    if [[ -z "$owner" || ! -x "$distrib" ]]; then
        log_warn "Nginx dari paket Ubuntu belum terpasang - lewati"
        return 0
    fi
    read -r srcpkg srcver < <(dpkg-query -W -f='${source:Package} ${source:Version}\n' "$owner" 2>/dev/null)
    if [[ -z "$srcpkg" || -z "$srcver" ]]; then
        log_warn "Tidak bisa membaca paket source untuk ${owner} - lewati"
        return 0
    fi

    tag=$(openssl_target_tag)
    version="${tag#openssl-}"
    prefix="${OPENSSL_PRIVATE_ROOT}/${version}"
    want="${srcpkg}=${srcver} openssl=${version}"

    if [[ "${YUUKA_FORCE_REBUILD:-0}" != 1 ]]; then
        if [[ "$(cat "$NGINX_OPENSSL_STATE" 2>/dev/null)" == "$want" ]] \
            && nginx_runs_private_openssl "$NGINX_BIN" "$version" "$prefix"; then
            log_ok "Nginx ${srcver} + OpenSSL ${version} sudah terpasang - lewati build"
            nginx_install_apt_guard
            return 0
        fi
        if [[ "$(cat "$NGINX_OPENSSL_FAILED" 2>/dev/null)" == "$want" ]]; then
            log_warn "Build Nginx ${srcver} + OpenSSL ${version} sudah pernah gagal di server ini - tidak diulang tiap update. Coba lagi: sudo yp custom-build openssl"
            return 1
        fi
    fi

    log_info "Target: Nginx ${srcver} (paket ${owner}) + OpenSSL ${version}"
    local rc=0
    nginx_openssl_build_and_swap "$distrib" "$srcpkg" "$srcver" "$tag" "$version" "$prefix" || rc=$?
    rm -rf "$OPENSSL_BUILD_DIR"
    if [[ $rc -ne 0 ]]; then
        [[ $rc -eq 1 ]] && echo "$want" > "$NGINX_OPENSSL_FAILED"
        log_warn "Nginx tetap memakai binary sebelumnya. Detail: $INSTALL_LOG_FILE"
        return 1
    fi

    echo "$want" > "$NGINX_OPENSSL_STATE"
    rm -f "$NGINX_OPENSSL_FAILED"
    local d
    for d in "$OPENSSL_PRIVATE_ROOT"/*; do
        [[ -d "$d" && "$d" != "$prefix" ]] && rm -rf "$d"
    done
    nginx_install_apt_guard
    log_ok "Nginx sekarang: $("$NGINX_BIN" -V 2>&1 | grep -m1 'built with OpenSSL')"
}

# ---------------------------------------------------------------------------
# Undo the old design: system OpenSSL back to Ubuntu's packages
# ---------------------------------------------------------------------------
openssl_distro_pkgs() {
    # held packages read "hi", not "ii" - the old design held what it replaced
    dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' openssl 'libssl3*' libssl-dev 2>/dev/null \
        | awk '$1 ~ /^.i/ && $2 !~ /-doc$/ {print $2}'
}

# True when files Ubuntu's packages shipped were overwritten: the libraries
# and the openssl binary, or with "lib" the libraries only.
openssl_system_replaced() {
    local pkg re='lib(ssl|crypto)\.so\.3$|/bin/openssl$'
    [[ "${1:-}" == lib ]] && re='lib(ssl|crypto)\.so\.3$'
    for pkg in $(openssl_distro_pkgs); do
        dpkg --verify "$pkg" 2>/dev/null | grep -qE "$re" && return 0
    done
    return 1
}

sshd_rejects_system_openssl() {
    local out
    [[ -x /usr/sbin/sshd ]] || return 1
    out=$(/usr/sbin/sshd -t 2>&1) && return 1
    grep -qiE 'openssl|libcrypto' <<<"$out"
}

# Headers, pkg-config/cmake files and link-time libraries make install_sw left
# behind that no package owns (all of them, when libssl-dev isn't installed).
# Left in place, anything compiled here later would build against 3.5 headers
# and then fail at runtime against Ubuntu's 3.0 library.
openssl_remove_stray_dev_files() {
    local libdir="/usr/lib/${OPENSSL_MULTIARCH}" owned f removed=0
    owned=$(dpkg -L libssl-dev 2>/dev/null)
    for f in /usr/include/openssl/* "${libdir}"/lib{ssl,crypto}.{so,a} \
        "${libdir}"/pkgconfig/{openssl,libssl,libcrypto}.pc "${libdir}"/cmake/OpenSSL/*; do
        [[ -e "$f" || -L "$f" ]] || continue
        grep -qxF "$f" <<<"$owned" && continue
        rm -f "$f" && removed=$((removed + 1))
    done
    rmdir "${libdir}/cmake/OpenSSL" /usr/include/openssl 2>/dev/null || true
    [[ $removed -gt 0 ]] && log_ok "${removed} file dev OpenSSL 3.5 lama (tanpa paket pemilik) dihapus"
    return 0
}

module_openssl_restore_system() {
    local nginx_rc="${1:-0}"

    if [[ -f "$OPENSSL_LEGACY_MARKER" ]]; then
        rm -f "$OPENSSL_LEGACY_MARKER"
        log_info "Penanda lama ${OPENSSL_LEGACY_MARKER} dihapus - modul ini tidak lagi mengganti OpenSSL sistem"
    fi
    state_has "openssl:upgraded" || openssl_system_replaced || return 0

    log_step "Kembalikan OpenSSL sistem ke paket Ubuntu (sisa desain lama)"
    # Nginx still on a 3.5 system library (its own build failed) would lose
    # 3.5 with this - only worth it when sshd is what that library breaks.
    if [[ $nginx_rc -ne 0 ]] && openssl_system_replaced lib && ! sshd_rejects_system_openssl; then
        log_warn "Dibiarkan dulu: Nginx khusus belum berhasil dibangun dan Nginx masih bergantung pada OpenSSL 3.5 sistem"
        return 0
    fi

    local pkgs=()
    mapfile -t pkgs < <(openssl_distro_pkgs)
    if [[ ${#pkgs[@]} -eq 0 ]]; then
        log_warn "Paket openssl/libssl3 Ubuntu tidak ditemukan - tidak ada yang bisa dipulihkan"
        return 1
    fi
    apt-mark unhold "${pkgs[@]}" >>"$INSTALL_LOG_FILE" 2>&1 || true

    if openssl_system_replaced; then
        log_info "Memasang ulang paket: ${pkgs[*]}"
        openssl_apt update -qq >>"$INSTALL_LOG_FILE" 2>&1 || true
        # The exact installed version first; if the archive has moved on
        # (likely - the packages sat on hold), the current distro build.
        if ! openssl_apt install --reinstall -y "${pkgs[@]}" >>"$INSTALL_LOG_FILE" 2>&1 \
            && ! openssl_apt install -y --no-remove "${pkgs[@]}" >>"$INSTALL_LOG_FILE" 2>&1; then
            log_error "Gagal memulihkan paket OpenSSL Ubuntu - cek $INSTALL_LOG_FILE"
            return 1
        fi
        ldconfig
    fi
    openssl_remove_stray_dev_files
    if openssl_system_replaced; then
        log_error "Masih ada file OpenSSL yang berbeda dari paket Ubuntu - cek: dpkg --verify ${pkgs[*]}"
        return 1
    fi
    sed -i '/^openssl:upgraded$/d' "$STATE_FILE" 2>/dev/null || true
    log_ok "OpenSSL sistem kembali ke paket Ubuntu: $(openssl version 2>&1 | head -1) (hold dilepas, ikut 'apt upgrade' lagi)"

    # Running processes keep the library they loaded; restart the PHP pools so
    # nothing long-lived stays on the deleted 3.5 copy. sshd loads the library
    # anew for every connection - only bring it back if it went down.
    local svc
    for svc in $(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]+-fpm\.service$'); do
        systemctl restart "$svc" >>"$INSTALL_LOG_FILE" 2>&1 && log_ok "${svc} di-restart"
    done
    if systemctl is-enabled --quiet ssh.service 2>/dev/null \
        && ! systemctl is-active --quiet ssh.service && ! systemctl is-active --quiet ssh.socket; then
        systemctl start ssh.service >>"$INSTALL_LOG_FILE" 2>&1 && log_ok "sshd dinyalakan lagi"
    fi
    return 0
}

module_openssl_run_all() {
    local rc=0
    module_openssl_nginx_build || rc=$?
    module_openssl_restore_system "$rc"
    return "$rc"
}
