# Model Keamanan

[← Kembali ke Home](Home.md)

| Lapisan | Mekanisme |
|---|---|
| Isolasi proses | Panel = user `panel` (pool PHP-FPM sendiri). Website PHP = `www-data`. Node.js/PM2 = user `nodeapps`. Ketiganya tidak saling bisa membaca file satu sama lain. |
| Privilese sistem | Satu jembatan: `panel-exec.sh` (root-owned, mode 700), dipanggil via `sudo -n` dari PHP. Sudoers (`/etc/sudoers.d/panel-exec`) membatasi user `panel` HANYA boleh menjalankan file itu, tanpa password. |
| Whitelist command | `panel-exec.sh` memakai `case` statement tertutup — subcommand di luar daftar langsung ditolak (exit 2). Setiap argumen divalidasi regex ketat (nama domain, nama app, nama db, port, dst) **di dua lapis**: sekali di PHP (`Validator` class) sebelum dikirim, sekali lagi di bash sebelum dieksekusi. Daftar lengkap subcommand: [Referensi panel-exec.sh](Panel-Exec-Reference.md). |
| Tanpa shell injection | `Executor::run()` (satu-satunya pemanggil proses eksternal di seluruh panel) memakai `proc_open()` dengan command dalam bentuk **array**, bukan string — argumen tidak pernah melewati parser shell, sehingga karakter apa pun dalam argumen tidak bisa menjadi metacharacter shell. |
| Tanpa `eval` | Tidak ada satupun `eval()` PHP maupun `eval` bash di seluruh codebase. |
| Fungsi berbahaya dimatikan | Pool PHP-FPM panel menonaktifkan `exec, passthru, shell_exec, system, popen, pcntl_exec` di `php.ini` (hanya `proc_open` yang aktif, dan hanya dipakai oleh `Executor`). |
| Sandbox filesystem | `open_basedir` pool panel dikunci ke `/opt/server-panel:/tmp:/proc` (`/proc` untuk statistik CPU/RAM read-only). Panel **tidak pernah** membaca langsung file milik `www-data`/`nodeapps` — semua lewat `panel-exec.sh`. |
| Autentikasi | `password_hash()` (bcrypt), session regenerasi setelah login & tiap 5 menit, idle timeout + absolute timeout, cookie `HttpOnly` + `Secure` + `SameSite=Lax`, rate limiting brute force (5x gagal / 15 menit per IP+username, tabel `login_attempts`), log semua percobaan login. |
| CSRF | Token per-session, divalidasi di setiap POST (`Csrf::validateRequest()`), dibandingkan dengan `hash_equals()`. |
| SQL Injection | Seluruh query panel pakai PDO prepared statement. Untuk operasi DDL (nama database/user, yang tidak bisa di-bind sebagai parameter), identifier divalidasi whitelist regex ketat sebelum interpolasi. |
| XSS | Semua output ke HTML lewat helper `e()` (htmlspecialchars ENT_QUOTES). |
| Path traversal | `Validator::relativeScriptPath()` menolak `..` dan path absolut; semua operasi filesystem privileged di `panel-exec.sh` memvalidasi hasil `realpath` berada di dalam base directory yang diizinkan sebelum bertindak (`require_path_within()`). |
| Secret at rest | Environment variable aplikasi Node.js dienkripsi AES-256-GCM (`EnvService`, kunci dari `APP_KEY` di `.env`, permission 600). Cloudflare Tunnel token tidak pernah masuk database, tidak pernah dicatat ke log, tidak pernah ditampilkan di UI (lihat [Cloudflare Tunnel](Cloudflare-Tunnel.md)). |
| RBAC | 4 role (`admin`, `operator`, `developer`, `viewer`), matriks permission di `Rbac` class, diperiksa di setiap action state-changing. Detail: [RBAC & Role](RBAC.md). |
| Audit trail | Setiap aksi penting (login, create/delete website/app/db, restart PM2, dst) dicatat ke tabel `activity_log`. `panel-exec.sh` sendiri juga punya audit log terpisah di `/opt/server-panel/storage/logs/panel-exec-audit.log` (timestamp, uid pemanggil, subcommand, status — **tanpa** payload rahasia). |
| Wildcard hostname | Cloudflare for SaaS Custom Hostname (`NginxService::enableWildcard()`/`NodeService::enableWildcard()`) sengaja membuat satu situs menjadi `default_server` Nginx yang menerima domain APA PUN, termasuk akses langsung ke IP server — kebalikan dari model "satu vhost = satu domain terdaftar" yang berlaku di semua situs lain. Dibatasi ke **satu situs saja di seluruh server** (`NginxService::wildcardHolder()` mengecek tabel `websites` dan `nodejs_apps` sekaligus) supaya tidak ada dua situs berebut slot `default_server` yang sama. |

## Prinsip Desain `panel-exec.sh`

Dari header komentar file itu sendiri (`panel-src/scripts/panel-exec.sh:10-22`) — aturan yang **tidak boleh dilonggarkan**:

- Whitelist subcommand tetap (blok `case` di paling bawah file). Subcommand
  tak dikenal → `exit 2`, tidak ada yang dieksekusi.
- Setiap argumen divalidasi regex ketat **sebelum** dipakai.
- Tidak ada `eval`. Tidak ada ekspansi variabel tanpa quote di command yang
  dieksekusi.
- Path file selalu diturunkan ulang dari identifier yang sudah divalidasi
  dan dikunci di bawah direktori dasar tetap (cek prefix via `realpath`) —
  **tidak pernah** menerima path mentah langsung dari pemanggil.
- Konten besar (config Nginx, file ecosystem PM2) dibaca dari **STDIN**,
  bukan dari argv, untuk menghindari jebakan panjang/quoting argv.
- Setiap pemanggilan dicatat ke audit log dengan timestamp, uid pemanggil,
  dan subcommand — **tidak pernah** dengan payload rahasia (nilai env,
  token).

## Alur Privilege Bridge

```
Browser (admin/operator)
   │  HTTPS + CSRF token + session
   ▼
Nginx  →  PHP-FPM pool "panel" (user: panel, open_basedir terkunci)
   │  Validator::xxx() divalidasi dulu di PHP
   ▼
Executor::run($subcommand, $args, $stdin, $timeout)
   │  proc_open() dengan argv array (bukan string shell)
   ▼
sudo -n /opt/server-panel/scripts/panel-exec.sh <subcommand> [args...]
   │  (sudoers: user panel HANYA boleh menjalankan file ini, NOPASSWD)
   ▼
panel-exec.sh (root) — validasi ulang regex, whitelist case statement
   │
   ▼
Operasi sistem sesungguhnya (nginx -t, pm2, mysqldump, certbot, dst)
   │
   ▼
Audit log: timestamp, uid, subcommand, status
```

Lihat [Referensi panel-exec.sh](Panel-Exec-Reference.md) untuk daftar
lengkap subcommand yang diizinkan lewat jembatan ini.

## Mitigasi php2shell (CVE-2026-109618 / CVE-2026-109619)

Dua bug inti PHP yang dirilis 21 Oktober 2026 (CVSS v4 9.2, RCE tanpa
autentikasi, detailnya bocor sehari sebelum patch keluar):

- **CVE-2026-109618**: alokasi memori gagal di tengah parsing request
  (form dengan nama field raksasa, atau multipart yang menghabiskan
  `memory_limit`) meninggalkan data `$_POST` setengah jadi yang kemudian
  "dibersihkan" sebagai data valid.
- **CVE-2026-109619**: state dari request sebelumnya di worker yang sama
  menunjuk ke memori yang sudah di-free. Bisa dieksploitasi lewat *session
  upload progress* (aktif secara default, tetap jalan walau aplikasi tidak
  memanggil `session_start()`), dan di PHP 8.5 lewat `fatal_error_backtraces`.

Perbaikan resmi: **8.2.35, 8.3.36, 8.4.27, 8.5.12**. Cabang 7.4/8.0/8.1 sudah
EOL, upstream hanya merilis patch sumber, jadi aman atau tidaknya bergantung
pada backport paket PPA/distro.

### Yang dipasang panel (`modules/php.sh`, `modules/nginx.sh`, `modules/panel.sh`)

Berjalan otomatis di setiap `sudo bash update.sh` / `install.sh` /
`yp custom-build php`, atau sendiri lewat `sudo yp php-security`:

| Lapisan | Isi | Menutup |
|---|---|---|
| Patch | `apt-get install --only-upgrade` untuk semua paket `php<versi>*` yang terpasang (config yang sudah disesuaikan dipertahankan) | Keduanya (satu-satunya perbaikan tuntas) |
| `conf.d/99-yuuka-php2shell.ini` per versi | `session.upload_progress.enabled = 0` (+ `fatal_error_backtraces = 0` untuk 8.5+). Dimuat sebelum request di-parse dan berlaku untuk semua pool (situs, panel, phpMyAdmin). Tetap dipasang walau sudah dipatch, sebagai lapisan tambahan | Jalur session 109619 |
| Restart pool | Hanya versi yang paket atau ini-nya berubah. State basi hidup di worker yang sudah berjalan | — |
| Penyaring Nginx vhost panel | `snippets/php2shell-guard.conf`: tolak (413) form `application/x-www-form-urlencoded` ≥ 2 MB atau tanpa `Content-Length`. Upload sah panel selalu multipart | Jalur nama-field-raksasa 109618 |
| Laporan | Status per versi: *patched* / *backport* / *vulnerable* / *unknown* + pindaian indikator | — |

**Kenapa vhost panel yang paling penting:** PHP mem-parse body POST
**sebelum** kode panel sempat mengecek login, dan pool panel bisa memanggil
`panel-exec.sh` sebagai root. Jadi RCE di pool panel praktis setara root.
Vhost situs pelanggan dan phpMyAdmin tidak mengatur `client_max_body_size`,
jadi sudah dibatasi bawaan Nginx 1 MB dan tidak perlu penyaring yang sama.

**Sengaja tidak diterapkan:** `memory_limit=-1` (mitigasi 109618 dari
advisory). Di server multi-situs, satu skrip yang bocor memori bisa
menghabiskan RAM seluruh server, dan jalur yang ditutupnya sudah tidak berlaku
untuk PHP-FPM (FPM mematikan worker saat startup gagal). Server ini tidak
memakai `mod_php`.

### Selama masih ada versi yang belum aman

- Aktifkan **BasicAuth panel** (Settings > Umum, atau `sudo yp basicauth`).
  Nginx menolak request tanpa kredensial **sebelum** PHP sempat mem-parse
  body. Security Entrance saja tidak cukup, karena endpoint PHP lain tetap
  menerima body.
- Pindahkan situs yang masih di 7.4/8.0/8.1 berstatus *vulnerable/unknown*
  ke 8.2+ (Website > Settings > Umum).
- Jalankan ulang `sudo yp php-security` beberapa jam sekali sampai PPA
  merilis versi perbaikan. Perintah ini aman diulang: kalau tidak ada yang
  berubah, tidak ada restart.

### Indikator eksploitasi (`yp php-security status`)

- `Allowed memory size of ... bytes exhausted` dengan `Unknown on line 0` di
  log error Nginx/panel, yang menandakan kehabisan memori **saat parsing request**.
- Worker PHP-FPM crash (`SIGSEGV`) di `/var/log/php*-fpm.log`. Bisa jadi
  tebakan alamat memori yang gagal.
- Shell/interpreter (`sh`, `bash`, `nc`, `python`, ...) yang induknya worker
  `php-fpm`. Ini bukti paling langsung. `sudo` dikecualikan karena panel
  sendiri memanggil `panel-exec.sh` lewat sudo.

Tidak satu pun indikator ini bukti pasti, dan tidak adanya indikator juga
tidak menjamin aman: eksploit yang berhasil bisa mengembalikan HTTP 200 tanpa
crash.
