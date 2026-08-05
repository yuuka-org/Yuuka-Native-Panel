# Yuuka Server Panel

Installer otomatis + panel manajemen web untuk Ubuntu Server: MariaDB, Nginx,
multi-versi PHP-FPM, phpMyAdmin, Node.js (NVM + PM2), dan panel administrasi
berbasis PHP native + Bootstrap 5 — tanpa Docker, tanpa Apache.

## 1. Arsitektur Sistem

![image](https://i.ibb.co.com/fYkKQzxk/img-github-yuukapanel-xasd.png)

Tiga pilar desain:

1. **Nginx adalah satu-satunya web server / reverse proxy.** Semua request
   HTTP(S) — website PHP, aplikasi Node.js, phpMyAdmin, dan panel itu sendiri
   — masuk lewat Nginx. Apache tidak pernah diinstall.
2. **PM2 adalah satu-satunya process manager Node.js.** Tidak ada aplikasi
   yang dijalankan dengan `nohup`, `screen`, `node app.js &`, atau systemd
   unit per-aplikasi. Semua status runtime (CPU, RAM, uptime, restart count,
   status) dibaca langsung dari `pm2 jlist` — database panel hanya menyimpan
   metadata (nama, domain, path, port), **bukan** status runtime.
3. **Satu jembatan privilese tunggal.** Panel PHP berjalan sebagai user
   sistem tanpa privilese (`panel`), terisolasi dari website (`www-data`) dan
   dari proses Node.js (`nodeapps`). Semua operasi yang butuh root (menulis
   config Nginx, mengelola PM2 sebagai user lain, menerbitkan SSL, dump
   database, dll) **hanya** bisa lewat satu script root-owned:
   `/opt/server-panel/scripts/panel-exec.sh`, dipanggil lewat satu baris
   sudoers `NOPASSWD` yang membatasi user `panel` HANYA menjalankan script
   itu. Lihat bagian [Security Model](#4-security-model) untuk detail.

## 2. Struktur Direktori

```
yuuka_native-panel/
├── install.sh                  # Orchestrator utama, jalankan dengan: sudo bash install.sh
├── modules/                    # Modul installer (bash), masing-masing idempotent
│   ├── lib.sh                  # Logging, warna CLI, helper idempotency, backup config
│   ├── system.sh                # Cek Ubuntu, update, dependency, user sistem (panel, nodeapps)
│   ├── mariadb.sh                # Install + secure MariaDB, akun panel_app & panel_provisioner
│   ├── nginx.sh                  # Install Nginx, snippet bersama (security header, proxy, ACME)
│   ├── php.sh                     # Install PHP 7.4-8.4 (PPA ondrej/php), pool tuning per versi
│   ├── nodejs.sh                  # NVM + Node 18/20/22 + PM2, semuanya di bawah user nodeapps
│   ├── phpmyadmin.sh              # Install phpMyAdmin manual (tanpa Apache), config Nginx
│   ├── ssl.sh                      # Certbot (webroot mode) + auto-renewal
│   ├── cloudflare.sh               # cloudflared opsional, token disimpan permission 600
│   └── panel.sh                     # Deploy panel, pool PHP-FPM khusus, sudoers, admin pertama
├── sql/schema.sql               # Skema database panel (server_panel)
├── nginx-templates/             # Contoh output konfigurasi Nginx (referensi/dokumentasi)
└── panel-src/                   # Source code panel, di-deploy ke /opt/server-panel
    ├── bootstrap.php             # Wiring: config, session aman, autoloader
    ├── .env.example
    ├── public/                    # Document root Nginx (satu-satunya folder yang exposed)
    │   ├── index.php, login.php, dashboard.php, websites.php, nodejs.php, ...
    │   ├── nodejs_env.php, nodejs_logs.php, nodejs_health.php
    │   ├── ajax_stats.php, ajax_pm2.php   # endpoint polling live (JSON)
    │   ├── assets/{css,js}/
    │   └── partials/{header,sidebar,footer,flash}.php
    ├── app/
    │   ├── config/      # Config.php (.env loader), database.php (2 koneksi PDO)
    │   ├── controllers/ # sengaja kosong - lihat catatan desain di bawah
    │   ├── services/     # Business logic + validasi + RBAC + audit log
    │   └── helpers/       # Auth, Csrf, Rbac, Validator, response helpers
    ├── scripts/            # panel-exec.sh (root-owned) + health_check_runner.php (cron)
    └── storage/
        ├── logs/            # executor.log, app-error.log, cron-panel-*.log, dst
        ├── backups/          # Hasil backup database/website/aplikasi
        └── sessions/          # Session PHP native (bukan di /tmp bersama)
```

**Catatan desain — `app/controllers/` sengaja kosong.** Setiap halaman di
`public/*.php` sudah berperan sebagai controller tipis: validasi request →
panggil method `Service` → render view di file yang sama. Menambahkan lapisan
`Controller` terpisah untuk setiap halaman hanya akan menjadi boilerplate
kosong (constructor yang memanggil satu service lalu include satu view) tanpa
manfaat nyata pada skala aplikasi ini. Folder tetap ada agar strukturnya
sesuai spesifikasi dan siap dipakai bila kompleksitas bertambah nanti.

## 3. Flow Instalasi

`install.sh` menjalankan modul secara berurutan, masing-masing modul
memeriksa state sebelum bertindak (lihat `state_mark` / `state_has` di
`modules/lib.sh`, disimpan di `/var/lib/yuuka-installer/install.state`):

1. **Cek root & versi Ubuntu** (20.04 / 22.04 / 24.04 didukung penuh; versi
   lain diperingatkan tapi bisa dilanjutkan).
2. **Input awal**: domain panel, email admin, mode deployment
   (direct/tunnel/hybrid).
3. **Update sistem** + dependency dasar + user sistem `panel` & `nodeapps`.
4. **MariaDB**: install, hardening dasar (setara `mysql_secure_installation`),
   buat akun `panel_provisioner` (privilese CREATE/DROP/CREATE USER/GRANT)
   dan `panel_app` (akses penuh hanya ke database `server_panel`), import
   skema.
5. **Nginx**: install, snippet bersama, hardening `server_tokens off`.
6. **PHP 7.4 – 8.4**: tambah PPA `ondrej/php`, install tiap versi + extension
   (bcmath, curl, fpm, gd, intl, mbstring, mysql, opcache, readline, soap,
   xml, zip, + redis/imagick jika tersedia). Versi yang gagal install
   (misalnya tidak tersedia untuk codename Ubuntu tertentu) dilewati tanpa
   menggagalkan seluruh instalasi. User memilih versi default di akhir.
7. **Node.js**: NVM diinstall di bawah `$HOME` milik user `nodeapps`, lalu
   Node 18/20/22, lalu PM2 global, lalu `pm2 startup systemd -u nodeapps`
   + `pm2 save` supaya semua aplikasi otomatis kembali online setelah reboot.
8. **phpMyAdmin**: didownload manual (bukan `apt install phpmyadmin`, yang
   menyeret dependency Apache/dbconfig-common), dikonfigurasi untuk PHP-FPM,
   user memilih akses via subdomain atau path `/phpmyadmin`.
9. **Panel**: deploy `panel-src/` ke `/opt/server-panel`, pool PHP-FPM khusus
   (user `panel`, open_basedir dikunci), `.env` dengan kredensial unik,
   sudoers untuk `panel-exec.sh`, akun admin pertama, vhost Nginx, cron
   health-check, logrotate.
10. **SSL**: opsional, via Certbot mode webroot (tidak menimpa konfigurasi
    Nginx yang sudah dibuat — sertifikat lalu dipasang lewat blok server
    terpisah).
11. **Cloudflare Tunnel**: opsional (mode tunnel/hybrid), token diminta
    interaktif (bukan `cloudflared tunnel login`), disimpan di
    `/etc/cloudflared/tunnel.token` permission 600.
12. **Ringkasan akhir**: URL panel, username, password (ditampilkan sekali),
    daftar versi PHP/Node.js aktif, lokasi log instalasi.

Instalasi **aman dijalankan ulang**: setiap modul memeriksa apakah package
sudah terinstall, apakah `.env` sudah ada (tidak pernah ditimpa), apakah user
panel sudah terdaftar (tidak membuat admin kedua), dan selalu membuat backup
sebelum mengubah file konfigurasi yang sudah ada (`backup_path()` di
`modules/lib.sh`, disimpan ke `/var/backups/yuuka-installer/`).

## 4. Security Model

| Lapisan | Mekanisme |
|---|---|
| Isolasi proses | Panel = user `panel` (pool PHP-FPM sendiri). Website PHP = `www-data`. Node.js/PM2 = user `nodeapps`. Ketiganya tidak saling bisa membaca file satu sama lain. |
| Privilese sistem | Satu jembatan: `panel-exec.sh` (root-owned, mode 700), dipanggil via `sudo -n` dari PHP. Sudoers (`/etc/sudoers.d/panel-exec`) membatasi user `panel` HANYA boleh menjalankan file itu, tanpa password. |
| Whitelist command | `panel-exec.sh` memakai `case` statement tertutup — subcommand di luar daftar langsung ditolak (exit 2). Setiap argumen divalidasi regex ketat (nama domain, nama app, nama db, port, dst) **di dua lapis**: sekali di PHP (`Validator` class) sebelum dikirim, sekali lagi di bash sebelum dieksekusi. |
| Tanpa shell injection | `Executor::run()` (satu-satunya pemanggil proses eksternal di seluruh panel) memakai `proc_open()` dengan command dalam bentuk **array**, bukan string — argumen tidak pernah melewati parser shell, sehingga karakter apa pun dalam argumen tidak bisa menjadi metacharacter shell. |
| Tanpa `eval` | Tidak ada satupun `eval()` PHP maupun `eval` bash di seluruh codebase. |
| Fungsi berbahaya dimatikan | Pool PHP-FPM panel menonaktifkan `exec, passthru, shell_exec, system, popen, pcntl_exec` di `php.ini` (hanya `proc_open` yang aktif, dan hanya dipakai oleh `Executor`). |
| Sandbox filesystem | `open_basedir` pool panel dikunci ke `/opt/server-panel:/tmp:/proc` (`/proc` untuk statistik CPU/RAM read-only). Panel **tidak pernah** membaca langsung file milik `www-data`/`nodeapps` — semua lewat `panel-exec.sh`. |
| Autentikasi | `password_hash()` (bcrypt), session regenerasi setelah login & tiap 5 menit, idle timeout + absolute timeout, cookie `HttpOnly` + `Secure` + `SameSite=Lax`, rate limiting brute force (5x gagal / 15 menit per IP+username), log semua percobaan login. |
| CSRF | Token per-session, divalidasi di setiap POST (`Csrf::validateRequest()`), dibandingkan dengan `hash_equals()`. |
| SQL Injection | Seluruh query panel pakai PDO prepared statement. Untuk operasi DDL (nama database/user, yang tidak bisa di-bind sebagai parameter), identifier divalidasi whitelist regex ketat sebelum interpolasi. |
| XSS | Semua output ke HTML lewat helper `e()` (htmlspecialchars ENT_QUOTES). |
| Path traversal | `Validator::relativeScriptPath()` menolak `..` dan path absolut; semua operasi filesystem privileged di `panel-exec.sh` memvalidasi hasil `realpath` berada di dalam base directory yang diizinkan sebelum bertindak. |
| Secret at rest | Environment variable aplikasi Node.js dienkripsi AES-256-GCM (`EnvService`, kunci dari `APP_KEY` di `.env`, permission 600). Cloudflare Tunnel token tidak pernah masuk database, tidak pernah dicatat ke log, tidak pernah ditampilkan di UI. |
| RBAC | 4 role (`admin`, `operator`, `developer`, `viewer`), matriks permission di `Rbac` class, diperiksa di setiap action state-changing. |
| Audit trail | Setiap aksi penting (login, create/delete website/app/db, restart PM2, dst) dicatat ke tabel `activity_log`. |

## 5. Kompatibilitas Ubuntu

- **Didukung penuh & diuji secara desain**: Ubuntu Server 20.04 LTS, 22.04
  LTS, 24.04 LTS.
- Versi lain akan memicu peringatan dan konfirmasi manual sebelum instalasi
  dilanjutkan (beberapa paket PHP dari PPA `ondrej/php` mungkin belum
  tersedia untuk codename yang sangat baru/lama — installer akan melewati
  versi yang gagal tanpa menghentikan seluruh proses).
- Arsitektur CPU: `amd64` dan `arm64` (cloudflared dan seluruh paket apt
  mendukung keduanya; installer mendeteksi arsitektur otomatis untuk
  download `cloudflared`).

## 6. Cara Pakai

```bash
git clone <repo-ini> yuuka-panel && cd yuuka-panel
sudo bash install.sh
```

Ikuti prompt interaktif (domain panel, email admin, mode deployment, pilihan
SSL, pilihan Cloudflare Tunnel). Di akhir instalasi, URL panel + kredensial
admin pertama ditampilkan **satu kali** — catat segera.

Menjalankan ulang `sudo bash install.sh` di server yang sudah terinstall
aman: package yang sudah ada dilewati, `.env` & data existing tidak disentuh.

### Role & Permission (RBAC)

| Role | Akses |
|---|---|
| **Admin** | Penuh — termasuk manajemen user, pengaturan, Cloudflare Tunnel. |
| **Operator** | Kelola website, aplikasi Node.js, database, domain, SSL, backup — tidak bisa mengelola user panel atau pengaturan server. |
| **Developer** | Deploy/kontrol aplikasi Node.js (start/stop/restart/reload), kelola environment variable, lihat log, kelola cron — tidak bisa hapus website/aplikasi/database. |
| **Viewer** | Hanya melihat status & monitoring. |

### Cloudflare Tunnel

Tiga mode dipilih saat instalasi (bisa diubah lewat re-run installer):

1. **Direct** — Nginx + IP publik + Let's Encrypt.
2. **Tunnel** — Cloudflare Tunnel saja, tidak ada port publik yang dibuka
   untuk panel/aplikasi (origin `127.0.0.1`).
3. **Hybrid** — Nginx tetap publik, Cloudflare Tunnel tersedia sebagai jalur
   tambahan.

Cloudflare Tunnel murni **network ingress** — autentikasi panel tetap wajib
berjalan di lapisan aplikasi. Untuk keamanan tambahan opsional, aktifkan
**Cloudflare Access** di Zero Trust Dashboard di depan tunnel (bukan
dependency wajib, tidak dikonfigurasi otomatis oleh installer ini).

### Backup & Restore

Panel bisa mem-backup database (mysqldump), folder website, dan folder
aplikasi Node.js kapan saja dari menu **Backup**. Sebelum *restore*, panel
otomatis membuat backup dari kondisi saat itu terlebih dahulu, sehingga
restore selalu bisa dibatalkan.

### Environment Variables Aplikasi Node.js

Dikelola per-aplikasi di halaman **Environment** masing-masing app. Nilai
secret disamarkan (`••••••••`) secara default dengan tombol show/hide,
mendukung import/export format `.env`. Perubahan baru berlaku setelah
menekan **Terapkan & Restart** (menulis ulang `ecosystem.config.cjs` lalu
`pm2 start ... --update-env`).

## 7. Simplifikasi yang Disengaja

Beberapa hal disederhanakan secara sadar untuk menjaga codebase tetap bisa
dipahami & diaudit, bukan karena keterbatasan teknis:

- Tidak ada layer ORM/query builder — PDO prepared statement langsung,
  cukup untuk skema yang tidak terlalu kompleks ini.
- `app/controllers/` kosong by design (lihat penjelasan di bagian struktur
  direktori).
- Health check HTTP dijalankan oleh cron `* * * * *` yang memanggil
  `scripts/health_check_runner.php` (bukan daemon terpisah) — cukup untuk
  interval minimum 10 detik yang didukung UI.
- phpMyAdmin diinstall manual dari tarball resmi (bukan `apt install
  phpmyadmin`) khusus untuk menghindari dependency Apache/dbconfig-common
  bawaan paket Ubuntu.

## 8. Menemukan Bug atau Kejanggalan?

Sebelum melapor, cek dulu [Troubleshooting](wiki/Troubleshooting.md) —
kemungkinan sudah pernah terjadi & ada solusinya di situ. Kalau belum
ada, laporkan lewat
[GitHub Issues](https://github.com/yuuka-org/Yuuka-Native-Panel/issues)
proyek ini, sertakan:

- Versi Ubuntu (`lsb_release -a`) dan mode deployment (`direct`/`tunnel`/
  `hybrid`).
- Langkah reproduksi & perilaku yang diharapkan vs. yang sebenarnya
  terjadi.
- Baris relevan dari log terkait kalau ada (lihat menu **Log** di panel,
  atau `storage/logs/php-fpm-error.log`, `storage/logs/panel-exec-audit.log`,
  `/var/log/nginx/panel-<domain>-error.log`) — **jangan** sertakan isi
  `.env`, token Cloudflare Tunnel, password, atau kredensial lain apa pun.

Untuk kerentanan keamanan (bukan bug fungsional biasa), lihat
[Model Keamanan](wiki/Keamanan.md) sebelum melaporkan secara publik di
Issues — pertimbangkan jalur pelaporan privat kalau tersedia.

## 9. Lisensi

[MIT License](LICENSE) — bebas dipakai, dimodifikasi, dan didistribusikan
ulang (termasuk untuk keperluan komersial), selama keterangan copyright
tetap disertakan. Tidak ada garansi apa pun (lihat teks lengkap lisensi).
