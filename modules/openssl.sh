#!/usr/bin/env bash
# ==============================================================================
# openssl.sh - Build & install upstream OpenSSL (3.5.x LTS track) system-wide,
#               replacing Ubuntu's distro OpenSSL 3.0.x build in place.
#
#               Why this exists: Ubuntu 24.04 LTS tracks OpenSSL 3.0.x for its
#               whole lifecycle (security backports only, never a feature
#               upgrade) - it never gains support for newer TLS 1.3 key
#               exchange groups. When a client's ClientHello only offers
#               key_share entries for a group this OpenSSL doesn't recognize
#               (observed live in production: Cloudflare's edge connecting to
#               a panel-managed origin, "SSL routines::no suitable key share",
#               OpenSSL error 0A000065), OpenSSL fails the handshake outright
#               instead of falling back via HelloRetryRequest - this shows up
#               to visitors as Cloudflare error 525, with ZERO trace in
#               Nginx's own access/error logs at the default log level (the
#               failure happens inside OpenSSL's C library during the
#               handshake itself; Nginx only forwards whatever OpenSSL
#               decides, and only logs it at all at "info" verbosity).
#
#               Deliberately replaces the SYSTEM OpenSSL (not a separate copy
#               statically linked into just Nginx) - OpenSSL's 3.x line
#               guarantees strict ABI/API compatibility across every 3.x
#               release (this was the explicit point of the 3.0 versioning
#               scheme), so every other program dynamically linked against
#               libssl.so.3/libcrypto.so.3 on this box (OpenSSH, curl, PHP,
#               Python, apt itself) keeps working completely unmodified and
#               benefits from the exact same upgrade - one OpenSSL version to
#               track and patch on this server, not two silently drifting
#               copies where a routine security update only reaches one of
#               them.
#
#               apt-mark hold on the distro package(s) afterwards is
#               deliberate and necessary: without it, a routine `apt upgrade`
#               would silently reinstall Ubuntu's stock 3.0.x build over ours
#               the next time ANY security patch lands for it, quietly
#               reintroducing this exact bug. The direct consequence: OpenSSL
#               security patches on this box are no longer automatic -
#               re-running this module (`sudo yp custom-build openssl`, or
#               just `sudo bash update.sh`, which calls it too) is what needs
#               to happen instead whenever a new 3.5.x point release ships.
# ==============================================================================

OPENSSL_MULTIARCH="$(dpkg-architecture -qDEB_HOST_MULTIARCH 2>/dev/null || echo x86_64-linux-gnu)"
OPENSSL_BUILD_DIR="/usr/local/src/panel-openssl-build"

# Queries GitHub's release API for the newest openssl-3.5.* tag (OpenSSL
# project's own designated LTS track, supported into the early 2030s) -
# never hardcoded, so re-running this module later for an urgent security
# patch actually picks up the new point release instead of silently
# reinstalling the exact same (by-then-outdated) version forever.
openssl_latest_35_tag() {
    local tag
    tag=$(curl -fsSL "https://api.github.com/repos/openssl/openssl/releases?per_page=100" 2>/dev/null \
        | grep -o '"tag_name": *"openssl-3\.5\.[0-9]*"' \
        | grep -o 'openssl-3\.5\.[0-9]*' \
        | sort -t. -k3 -n \
        | tail -1)
    if [[ -z "$tag" ]]; then
        # GitHub API unreachable/rate-limited - fall back to a known-good
        # pinned release rather than hard-failing the whole install/update.
        # Still on the 3.5 LTS track, still carries the key-share fix - just
        # not necessarily the newest point release.
        tag="openssl-3.5.7"
        log_warn "Tidak bisa mendeteksi rilis OpenSSL 3.5.x terbaru dari GitHub API - pakai fallback ${tag}"
    fi
    echo "$tag"
}

openssl_installed_version() {
    openssl version 2>/dev/null | awk '{print $2}'
}

module_openssl_build_deps() {
    log_step "Install dependency build OpenSSL"
    apt_install build-essential perl zlib1g-dev
}

module_openssl_upgrade_system() {
    log_step "Upgrade OpenSSL sistem ke jalur LTS 3.5.x"

    local tag version current
    tag=$(openssl_latest_35_tag)
    version="${tag#openssl-}"
    current=$(openssl_installed_version)

    if [[ "$current" == "$version" ]]; then
        log_ok "OpenSSL sistem sudah di versi terbaru jalur 3.5.x (${current}) - lewati build"
        state_mark "openssl:upgraded"
        return 0
    fi

    log_info "OpenSSL sistem saat ini: ${current:-tidak terdeteksi} -> target: ${version}"

    module_openssl_build_deps

    rm -rf "$OPENSSL_BUILD_DIR"
    mkdir -p "$OPENSSL_BUILD_DIR"

    local tarball="${tag}.tar.gz"
    local base_url="https://github.com/openssl/openssl/releases/download/${tag}"

    log_info "Mengunduh source OpenSSL ${version}"
    if ! curl -fsSL -o "${OPENSSL_BUILD_DIR}/${tarball}" "${base_url}/${tarball}"; then
        log_error "Gagal mengunduh source OpenSSL ${version} - OpenSSL sistem TIDAK diubah (masih ${current})"
        return 1
    fi
    if ! curl -fsSL -o "${OPENSSL_BUILD_DIR}/${tarball}.sha256" "${base_url}/${tarball}.sha256"; then
        log_error "Gagal mengunduh checksum OpenSSL ${version} - OpenSSL sistem TIDAK diubah (masih ${current})"
        return 1
    fi

    if ! (cd "$OPENSSL_BUILD_DIR" && sha256sum -c "${tarball}.sha256" >>"$INSTALL_LOG_FILE" 2>&1); then
        log_error "Checksum source OpenSSL ${version} TIDAK COCOK - dibatalkan, file tidak dipercaya. OpenSSL sistem TIDAK diubah (masih ${current})"
        return 1
    fi
    log_ok "Checksum source OpenSSL ${version} terverifikasi"

    (cd "$OPENSSL_BUILD_DIR" && tar xzf "$tarball")
    local src_dir="${OPENSSL_BUILD_DIR}/${tag}"

    # --libdir dicocokkan ke layout multiarch Debian/Ubuntu supaya hasil
    # build menimpa PERSIS file yang sudah dipakai setiap program lain di
    # server ini (bukan lokasi baru yang harus "menang" lewat urutan
    # pencarian linker - itu rapuh dan sulit diverifikasi). no-tests: rilis
    # upstream sudah ditest oleh proyek OpenSSL sendiri sebelum dirilis, ini
    # cuma rebuild dari source yang sama, bukan build development.
    if ! (
        cd "$src_dir" \
            && ./config shared --prefix=/usr --libdir="lib/${OPENSSL_MULTIARCH}" --openssldir=/etc/ssl no-tests >>"$INSTALL_LOG_FILE" 2>&1 \
            && make -j"$(nproc)" >>"$INSTALL_LOG_FILE" 2>&1
    ); then
        log_error "Build OpenSSL ${version} gagal - cek $INSTALL_LOG_FILE. OpenSSL sistem TIDAK diubah (masih ${current})."
        return 1
    fi
    log_ok "Build OpenSSL ${version} sukses"

    # install_sw (bukan 'make install' penuh) - hanya memasang
    # library/binary/header, TIDAK menyentuh openssldir (/etc/ssl - CA
    # bundle & konfigurasi Ubuntu yang sudah ada di sana) maupun man pages.
    # Ini target resmi OpenSSL sendiri untuk upgrade in-place tanpa merusak
    # setup sertifikat yang sudah berjalan di server.
    if ! (cd "$src_dir" && make install_sw >>"$INSTALL_LOG_FILE" 2>&1); then
        log_error "Install OpenSSL ${version} gagal - cek $INSTALL_LOG_FILE"
        return 1
    fi

    ldconfig

    local installed
    installed=$(openssl_installed_version)
    if [[ "$installed" != "$version" ]]; then
        log_error "Setelah install, 'openssl version' masih melapor ${installed:-tidak terdeteksi} (bukan ${version}). Cek manual: 'ldconfig -p | grep libssl.so.3' dan 'which openssl'."
        return 1
    fi
    log_ok "OpenSSL sistem sekarang: ${installed}"

    # Cegah 'apt upgrade' menimpa balik ke build distro (3.0.x) diam-diam di
    # kemudian hari - lihat komentar header file ini soal konsekuensinya
    # (patch keamanan OpenSSL jadi manual, bukan otomatis lagi, setelah ini).
    local lib_path bin_path pkgs
    lib_path=$(ldconfig -p | awk '/libssl\.so\.3 /{print $NF; exit}')
    bin_path=$(command -v openssl)
    pkgs=$( { [[ -n "$lib_path" ]] && dpkg -S "$lib_path" 2>/dev/null; [[ -n "$bin_path" ]] && dpkg -S "$bin_path" 2>/dev/null; } | cut -d: -f1 | sort -u)
    if [[ -n "$pkgs" ]]; then
        # shellcheck disable=SC2086
        apt-mark hold $pkgs >>"$INSTALL_LOG_FILE" 2>&1
        log_ok "Paket distro di-hold supaya tidak menimpa balik: $(echo $pkgs | tr '\n' ' ')"
    else
        log_warn "Tidak menemukan paket dpkg pemilik libssl.so.3/openssl - lewati apt-mark hold"
    fi

    # Proses yang SUDAH jalan tetap memakai library lama yang sudah
    # ter-load ke memori (Linux tidak mencabut halaman memori proses yang
    # berjalan) - restart eksplisit diperlukan supaya benar-benar pindah ke
    # OpenSSL baru. sshd sengaja TIDAK di-restart otomatis di sini - risiko
    # memutus sesi SSH yang sedang aktif menjalankan script ini sendiri;
    # restart manual saat nyaman: 'sudo systemctl restart ssh'.
    systemctl restart nginx >>"$INSTALL_LOG_FILE" 2>&1 && log_ok "nginx di-restart, sekarang pakai OpenSSL ${installed}"
    local phpfpm
    for phpfpm in $(systemctl list-units --type=service --state=running 2>/dev/null | grep -o 'php[0-9.]*-fpm\.service'); do
        systemctl restart "$phpfpm" >>"$INSTALL_LOG_FILE" 2>&1 && log_ok "${phpfpm} di-restart"
    done
    log_warn "sshd belum di-restart (sengaja, supaya tidak memutus sesi SSH aktif) - jalankan 'sudo systemctl restart ssh' manual kalau mau semua proses pakai OpenSSL baru."

    rm -rf "$OPENSSL_BUILD_DIR"
    state_mark "openssl:upgraded"
}

module_openssl_run_all() {
    module_openssl_upgrade_system
}
