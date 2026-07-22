# Yuuka Server Panel — Wiki

Installer otomatis + panel manajemen web untuk Ubuntu Server: MariaDB, Nginx,
multi-versi PHP-FPM, phpMyAdmin, Node.js (NVM + PM2), dan panel administrasi
berbasis PHP native + Bootstrap 5 — tanpa Docker, tanpa Apache.

## Daftar Isi

1. [Arsitektur](Arsitektur.md) — tiga pilar desain, struktur direktori, alasan `app/controllers/` kosong
2. [Instalasi](Instalasi.md) — cara pakai, urutan tahap `install.sh`, kompatibilitas Ubuntu, idempotency
3. [Mode Deployment & Cloudflare Tunnel](Cloudflare-Tunnel.md) — direct/tunnel/hybrid, cara kerja token, systemd unit
4. [Model Keamanan](Keamanan.md) — isolasi proses, privilege bridge, whitelist command, proteksi injeksi
5. [RBAC & Role](RBAC.md) — 4 role, matriks permission lengkap
6. [Fitur Panel](Fitur-Panel.md) — penjelasan tiap menu (Website, Node.js, Database, Domain, Cron, Backup, Log, dst)
7. [Skema Database](Skema-Database.md) — seluruh tabel `server_panel` beserta relasinya
8. [Referensi `panel-exec.sh`](Panel-Exec-Reference.md) — daftar lengkap subcommand privilege bridge
9. [Konfigurasi (`.env` & State)](Konfigurasi.md) — semua environment variable dan file state installer
10. [Troubleshooting](Troubleshooting.md) — masalah umum & cara diagnosa
11. [Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) — cara cek username & reset password lewat database
12. [CLI `yp`](Yp-CLI.md) — CLI administrasi server via SSH (start/stop/restart, reset kredensial, repair, update, custom-build)

## Ringkasan Cepat

| | |
|---|---|
| **Web server** | Nginx saja (satu-satunya reverse proxy, tidak ada Apache) |
| **Process manager Node.js** | PM2 saja, dikelola user sistem `nodeapps` |
| **Bahasa panel** | PHP native (tanpa framework/ORM), Bootstrap 5 |
| **Database panel** | MariaDB, database `server_panel` |
| **Privilege bridge** | `/opt/server-panel/scripts/panel-exec.sh` (root-owned, whitelist command) |
| **Direktori deploy panel** | `/opt/server-panel` |
| **User sistem panel** | `panel` (PHP-FPM pool sendiri, tanpa privilese) |
| **Instalasi** | `sudo bash install.sh`, aman dijalankan ulang |

## Sumber Kebenaran

Wiki ini disusun dari pembacaan langsung source code di repo (`README.md`,
`modules/*.sh`, `panel-src/**`, `sql/schema.sql`) per commit terakhir yang
tersedia saat wiki ini ditulis. Kalau ada perbedaan antara wiki dan kode
aktual (misalnya setelah fitur baru ditambahkan), **kode adalah sumber
kebenaran** — anggap bagian wiki yang bersangkutan sudah usang dan perlu
diperbarui.
