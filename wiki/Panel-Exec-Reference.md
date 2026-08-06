# Referensi `panel-exec.sh`

[← Kembali ke Home](Home.md)

`/opt/server-panel/scripts/panel-exec.sh` adalah **satu-satunya** jembatan
privilese antara panel (user `panel`, tanpa privilese) dan operasi
level-root. Lihat [Model Keamanan](Keamanan.md) untuk prinsip desainnya.
Dipanggil dari PHP lewat `Executor::run($subcommand, $args, $stdin,
$timeout)` → `sudo -n panel-exec.sh <subcommand> [args...]`.

Setiap pemanggilan (sukses maupun ditolak) dicatat ke
`/opt/server-panel/storage/logs/panel-exec-audit.log`.

## Daftar Subcommand

| Subcommand | Argumen | STDIN | Fungsi |
|---|---|---|---|
| `nginx-test` | – | – | `nginx -t` |
| `nginx-reload` | – | – | `nginx -t` lalu `systemctl reload nginx` |
| `nginx-write-config` | `<site>` | isi config | Tulis `sites-available/<site>.conf`, validasi `nginx -t`, rollback otomatis ke backup kalau invalid |
| `nginx-enable` | `<site>` | – | Symlink ke `sites-enabled`, validasi, reload |
| `nginx-disable` | `<site>` | – | Hapus symlink `sites-enabled`, reload |
| `nginx-delete` | `<site>` | – | Hapus config available+enabled, reload |
| `nginx-write-ratelimit-zones` | – | isi config `limit_req_zone` gabungan | Tulis `/etc/nginx/conf.d/panel-rate-limits.conf` (full di-generate ulang dari semua situs yang Traffic Control-nya aktif tiap kali berubah), validasi `nginx -t`, reload |
| `pm2-deploy` | `<app>` `[node_version]` `[build_command]` | isi `ecosystem.config.cjs` | Tulis ecosystem file (selalu `.cjs`, bukan `.js`, supaya kebal dari `"type": "module"` di `package.json` app itu sendiri) di bawah `nodeapps`, scaffold placeholder HTTP server di path script kalau belum ada file sama sekali (deploy pertama sebelum kode diupload), `nvm use <node_version>` (kalau diisi) sebelum start supaya versi Node per-app benar-benar dipakai (bukan cuma metadata), jalankan `build_command` (kalau diisi, TIDAK dijalankan saat create pertama kali karena folder masih kosong) di folder app, `pm2 start --update-env`, `pm2 save` |
| `pm2-start` / `pm2-stop` / `pm2-restart` / `pm2-reload` | `<app>` | – | Kontrol proses PM2 (sebagai user `nodeapps`) |
| `pm2-reset` | `<app>` | – | `pm2 reset` — reset counter Restarts ke 0 |
| `pm2-delete` | `<app>` | – | `pm2 delete` + `pm2 save` |
| `pm2-jlist` | – | – | `pm2 jlist` — sumber kebenaran status runtime Node.js |
| `pm2-describe` | `<app>` | – | `pm2 describe` |
| `pm2-logs` | `<app>` `[lines]` | – | Baca langsung file log live (bukan `pm2 logs`, tidak ada prefix `N\|nama \|`), maks 1000 baris dipaksa server |
| `pm2-logs-size` | `<app>` | – | Ukuran file log live saat ini (byte) — titik mulai stream real-time |
| `pm2-logs-tail` | `<app>` `<offset>` | – | Baca isi BARU sejak `offset` (baris pertama output = offset baru) — dipakai `nodejs_logs_stream.php` (SSE) |
| `pm2-logs-list` | `<app>` | – | Daftar file log arsip (per run start/restart/reload/deploy), terbaru dulu |
| `pm2-logs-read-archive` | `<app>` `<file>` `[lines]` | – | Baca satu file log arsip tertentu milik app itu |
| `pm2-flush` | `<app>` | – | Bersihkan log PM2 aplikasi (file log live) |
| `pm2-save` | – | – | `pm2 save` |
| `certbot-issue` | `<domain>` `<email>` | – | `certbot certonly --webroot` - hanya menerbitkan berkas sertifikat, TIDAK menyentuh Nginx. `SSLService::issueForDomain()` yang menerapkan blok 443-nya setelah ini sukses (lihat `applySslForDomain()` di `NginxService`/`NodeService`) |
| `certbot-remove` | `<domain>` | – | `certbot delete --cert-name`; kalau domain tidak dikenal certbot (mis. sertifikat manual upload), fallback ke `rm -rf` langsung berkas di `/etc/letsencrypt/live/<domain>/` supaya Remove SSL tetap berhasil apa pun asal sertifikatnya |
| `panel-ssl-issue` | `<email>` | – | SSL untuk domain PANEL sendiri (Settings > SSL Panel) - domain diambil dari vhost panel yang sudah ada di disk, BUKAN dari argumen caller. Setelah certbot sukses, otomatis jalankan `yp repair panel` supaya vhost dapat blok `listen 443` + `.env` ikut sinkron (`SESSION_SECURE_COOKIE`/`APP_URL`) - lihat `wiki/Troubleshooting.md` |
| `ssl-manual-upload` | `<domain>` | JSON `{"cert":"...","key":"..."}` | Upload sertifikat SSL manual (Domain Management) - divalidasi ganda: PHP (`openssl_x509_check_private_key` dkk, sebelum dikirim) DAN bash (`openssl x509`/`openssl pkey` + cocokkan modulus, sebelum ditulis). Ditulis ke path yang SAMA PERSIS dengan konvensi certbot (`/etc/letsencrypt/live/<domain>/{fullchain,privkey}.pem`) supaya Nginx tidak perlu tahu asal sertifikatnya |
| `service-status` | `<svc>` | – | `systemctl is-active` — whitelist: `nginx`, `mariadb`, `cloudflared`, `php{7.4-8.4}-fpm` |
| `service-restart` | `<svc>` | – | `systemctl restart` — whitelist sama dengan `service-status` |
| `installer-version-info` / `installer-check-update` / `installer-self-update` / `installer-self-update-status` | – | – | Info versi & update mandiri installer/CLI `yp` |
| `mysqldump-db` | `<db>` `<outfile>` | – | `mysqldump --single-transaction --routines --triggers -u root`, output dikunci di `storage/backups` |
| `mysql-restore-db` | `<db>` `<infile>` | – | `mysql -u root <db> < infile` |
| `cloudflared-status` | – | – | `systemctl is-active cloudflared` |
| `cloudflared-start` / `-stop` / `-restart` | – | – | Kontrol service cloudflared |
| `cloudflared-version` | – | – | `cloudflared --version` |
| `disk-usage` | – | – | `df` untuk `/` (panel tidak bisa panggil `disk_total_space()` sendiri karena `open_basedir` tidak termasuk `/`) |
| `fs-mkdir-website` | `<domain>` | – | Buat `/var/www/<domain>/public`, chown `www-data` |
| `fs-remove-website` | `<domain>` | – | Hapus `/var/www/<domain>` (menolak menghapus base dir itu sendiri) |
| `fs-remove-nodeapp` | `<app>` | – | Hapus `/home/nodeapps/apps/<app>` |
| `git-clone-website` | `<domain>` `<repo_url>` `[branch]` | – | `git clone --depth 1` langsung ke `/var/www/<domain>` (bukan sub-folder) — repo harus punya folder `public/` sendiri di root-nya (konvensi Laravel/Symfony/dst), itu yang jadi document root. HTTPS saja, token repo privat disisipkan di URL |
| `git-pull-website` | `<domain>` | – | `git pull --ff-only` di folder website — gagal bersih (tidak pernah merge/rebase) kalau ada perubahan lokal yang konflik atau branch divergen |
| `git-status-website` | `<domain>` | – | Branch + commit hash + pesan commit + tanggal terakhir, record NUL-delimited (`is_git`, `branch`, `commit`, `message`, `date`) |
| `port-check` | `<port>` | – | Cek port sedang listening atau bebas (`ss -ltn`) |
| `files-list` | `<scope>` `<name>` `[relpath]` | – | List isi direktori (NUL-delimited: `type`, `size`, `mtime`, `mode`, `name`) |
| `files-read` | `<scope>` `<name>` `<relpath>` | – | Baca isi file mentah (raw output, tidak di-`trim()`) |
| `files-write` | `<scope>` `<name>` `<relpath>` | isi file | Tulis file, chown ke pemilik scope |
| `files-mkdir` | `<scope>` `<name>` `<relpath>` | – | `mkdir -p`, chown |
| `files-delete` | `<scope>` `<name>` `<relpath>` `[orphan-confirmed]` | – | Soft-delete — dipindah ke `.trash/` di dalam scope, bukan `rm -rf` langsung |
| `files-rename` | `<scope>` `<name>` `<relpath>` `<newbasename>` `[orphan-confirmed]` | – | Rename dalam direktori yang sama saja |
| `files-extract-zip` | `<scope>` `<name>` `[relpath]` | isi ZIP | Ekstrak ZIP yang baru di-upload (belum pernah jadi file fisik) ke folder tujuan, dengan pengecekan zip-slip |
| `files-extract` | `<scope>` `<name>` `<relpath-zip>` | – | Ekstrak file `.zip` yang **sudah ada di disk** ke folder baru di sebelahnya (nama = nama ZIP tanpa ekstensi) |
| `files-compress` | `<scope>` `<name>` `<relpath-dir>` `<dest_name.zip>` `<item1>` `[item2...]` | – | Bikin ZIP baru dari beberapa file/folder terpilih (maks 100 item) di direktori yang sama |
| `files-copy` / `files-move` | `<src_scope>` `<src_name>` `<src_relpath>` `<dest_scope>` `<dest_name>` `<dest_relpath>` `[orphan-confirmed]` | – | Salin/pindah, termasuk lintas website↔website atau nodeapp↔nodeapp (tidak lintas Website↔Node.js) |
| `files-chmod` | `<scope>` `<name>` `<relpath>` `<mode>` `[orphan-confirmed]` | – | `chmod`, 3 digit oktal, digit terakhir (other) tidak boleh punya bit tulis |
| `files-search` | `<scope>` `<name>` `<query>` | – | `find -iname` dalam scope, maks 500 hasil, timeout 20 detik |
| `files-trash-list` | `<scope>` `<name>` | – | Isi Recycle Bin scope tsb |
| `files-trash-restore` | `<scope>` `<name>` `<trash_entry>` | – | Kembalikan ke lokasi asal (dicatat di file `.origpath` sidecar) |
| `files-trash-delete` | `<scope>` `<name>` `<trash_entry>` | – | Hapus permanen satu entri trash |
| `files-trash-empty` | `<scope>` `<name>` | – | Kosongkan seluruh Recycle Bin scope tsb |
| `backup-tar-website` | `<domain>` `<outfile>` | – | `tar czf` folder website ke `storage/backups` |
| `backup-tar-nodeapp` | `<app>` `<outfile>` | – | `tar czf` folder aplikasi Node.js |
| `restore-tar-website` | `<infile>` `<domain>` | – | Extract tar ke `/var/www`, chown `www-data` |
| `restore-tar-nodeapp` | `<infile>` `<app>` | – | Extract tar ke `/home/nodeapps/apps`, chown `nodeapps` |
| `backup-upload-s3` | `<filename>` | JSON `{"endpoint","region","bucket","prefix","access_key","secret_key"}` | Upload satu file backup (sudah ada di `storage/backups`) ke storage S3-compatible (AWS S3 atau Backblaze B2 via `--endpoint-url`) pakai `aws s3 cp`. Kredensial hanya lewat stdin/env var proses `aws`, tidak pernah lewat argv/log |
| `backup-upload-gdrive` | `<filename>` | JSON `{"token","client_id","client_secret","folder_id","prefix"}` | Upload satu file backup ke Google Drive pakai `rclone copyto`. `token` adalah hasil `rclone authorize "drive"` yang dijalankan admin sendiri (panel tidak melakukan alur OAuth). Config rclone dibuat di file sementara per-panggilan lalu langsung dihapus setelah upload |
| `cron-write` | `<jobid>` (`panel-<id>`) | isi file cron | Tulis `/etc/cron.d/<jobid>` |
| `cron-delete` | `<jobid>` | – | Hapus file cron |
| `log-tail` | `<logkey>` `[lines]` | – | Tail log, whitelist logkey, maks 2000 baris |
| `log-clear` | `<logkey>` | – | Kosongkan log (hanya `nginx-access:*` / `nginx-error:*`) |
| `log-traffic-daily` | `<domain>` | – | Request per hari dari access log domain tsb (termasuk yang sudah dirotasi `.gz`), output `YYYY-MM-DD<TAB>count` |
| `panel-basicauth-set` | `enable <user> <bcrypt_hash>` atau `disable` | – | Tulis/hapus snippet `auth_basic` di vhost panel |
| `panel-security-entrance-set` | `enable <path>` atau `disable` | – | Pindahkan form login panel ke path rahasia — lihat [Model Keamanan](Keamanan.md) |
| `plugin-install-zip` | – | isi ZIP plugin | Ekstrak ke `PLUGIN_DIR/<slug>` — **slug dibaca dari `plugin.json` di dalam paket**, bukan argumen. Output berisi baris `SLUG:<slug>` untuk dibaca PHP |
| `plugin-install-git` | `<repo_url>` `[branch]` | – | `git clone --depth 1` ke lokasi sementara, baca slug dari `plugin.json`, pindah ke `PLUGIN_DIR/<slug>` |
| `plugin-remove` | `<slug>` | – | Hapus `PLUGIN_DIR/<slug>` sepenuhnya |
| `plugin-exec` | `<slug>` `<script>` `[args...]` | opsional, diteruskan ke script | **Trust model root penuh** — jalankan `PLUGIN_DIR/<slug>/bin/<script>.sh` sebagai root apa adanya. Lihat [Pengembangan Plugin](Plugin-Development.md) |

Subcommand di luar daftar ini **selalu** ditolak (`exit 2`), tidak peduli
argumen apa pun yang diberikan. Daftar putih ini ada **dua lapis** — sekali
di `panel-exec.sh` sendiri (blok `case` dispatch) dan sekali lagi di sisi
PHP (`Executor::WHITELIST`); keduanya harus sinkron atau subcommand yang
baru ditambahkan akan ditolak PHP sebelum sempat menyentuh
`panel-exec.sh` sama sekali.

## Pola Validasi Argumen

Semua argumen dicocokkan ke salah satu regex tetap sebelum dipakai
(`require_match`):

| Nama | Regex | Dipakai untuk |
|---|---|---|
| `RE_SITENAME` | `^[a-zA-Z0-9._-]{1,200}$` | Nama file config Nginx |
| `RE_APPNAME` | `^[a-zA-Z0-9_-]{1,64}$` | Nama aplikasi Node.js / PM2 |
| `RE_DOMAIN` | `^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$` | Nama domain |
| `RE_EMAIL` | `^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$` | Email (Certbot) |
| `RE_DBNAME` | `^[a-zA-Z0-9_]{1,64}$` | Nama database |
| `RE_LINES` | `^[0-9]{1,4}$` | Jumlah baris log |
| `RE_PORT` | `^[0-9]{1,5}$` | Nomor port |
| `RE_CRONID` | `^panel-[0-9]+$` | ID file cron |
| `RE_CHMOD_MODE` | `^[0-7][0-7][0-7]$` | Mode chmod File Manager — digit terakhir (other) tidak boleh 2/3/6/7 (bit tulis) |
| `RE_FM_SCOPE` | `^(website\|nodeapp\|www\|nodeapps)$` | Scope File Manager |
| `RE_NODE_VERSION` | `^[0-9]{1,3}$` | Versi Node.js untuk `nvm use` di `pm2-deploy` |
| `RE_BUILD_COMMAND` | `^[a-zA-Z0-9_./ -]{1,255}$` | Build command Node.js app — charset sengaja tidak mengizinkan karakter shell sama sekali (titik koma, pipe, ampersand, backtick, `$()`, kurung kurawal, `<>`), walau tetap diinterpolasi ke dalam string `bash -lc "..."` di `as_nodeapps()` |
| `RE_GIT_URL` | `^https://[a-zA-Z0-9._~:/?#@!$&*+,;=%-]{1,200}$` | URL repo Git (HTTPS saja — tidak ada dukungan `git@host:path` SSH) |
| `RE_GIT_BRANCH` | `^[a-zA-Z0-9._/-]{1,200}$` | Nama branch Git |
| `RE_SECURITY_ENTRANCE_PATH` | `^[a-zA-Z0-9_-]{3,64}$` | Path rahasia Security Entrance |
| `RE_BASICAUTH_USERNAME` | `^[a-zA-Z0-9_.-]{3,64}$` | Username BasicAuth |
| `RE_BCRYPT_HASH` | `^\$2[abxy]\$[0-9]{2}\$[A-Za-z0-9./]{53}$` | Hash bcrypt password BasicAuth |
| `RE_RESTARTABLE_SERVICE` | `^(nginx\|mariadb\|cloudflared\|php{7.4-8.4}-fpm)$` | Whitelist `service-restart` |
| `RE_ENABLE_DISABLE` | `^(enable\|disable)$` | Mode `enable`/`disable` untuk BasicAuth & Security Entrance |

> **Catatan bounded repetition (`{n,m}`):** hindari batas atas yang terlalu
> besar tanpa alasan (misal `{1,500}`) — beberapa implementasi regex
> (`RE_DUP_MAX`) diam-diam gagal mencocokkan APAPUN begitu batas atasnya
> melewati ambang tertentu (dikonfirmasi langsung: `{1,256}` sudah gagal,
> `{1,200}` masih aman, di lingkungan yang dipakai untuk kerja pada
> codebase ini). Kalau menambah regex baru dengan batas besar, uji dulu
> `[[ "test" =~ $RE_VAR ]]` manual sebelum deploy.

Path file/direktori selalu ditambah pengecekan `require_path_within()`:
`realpath -m` hasilnya harus berada tepat di bawah base directory tetap
(`/var/www`, `/home/nodeapps/apps`, `storage/backups`, dst) — kalau tidak,
langsung ditolak, tidak peduli apakah regex nama-nya lolos.

## Menambah Subcommand Baru

Kalau perlu menambah operasi privileged baru:

1. Tulis fungsi `op_xxx()` baru mengikuti pola validasi di atas — **selalu**
   validasi argumen dulu sebelum dipakai, jangan pernah interpolasi
   variabel yang belum divalidasi ke command yang dieksekusi.
2. Tambahkan satu baris ke blok `case` dispatch di paling bawah file.
3. Pastikan pemanggil di PHP (`Executor::run()`) juga memvalidasi argumen
   yang sama di sisi PHP (`Validator` class) — validasi dua lapis adalah
   prinsip desain yang tidak boleh dilewati (lihat
   [Model Keamanan](Keamanan.md)).
