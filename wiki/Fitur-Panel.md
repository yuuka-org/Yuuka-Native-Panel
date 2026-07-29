# Fitur Panel

[← Kembali ke Home](Home.md)

Menu sidebar (`panel-src/public/partials/sidebar.php`) dan permission RBAC
yang menjaganya (lihat [RBAC & Role](RBAC.md) untuk role mana yang punya
akses):

| Menu | File halaman | Permission view | Service utama |
|---|---|---|---|
| Dashboard | `dashboard.php` | `monitoring.view` | `SystemService` |
| Website PHP | `websites.php` | `website.view` | `NginxService` |
| Node.js Apps | `nodejs.php` | `nodejs.view` | `NodeService`, `EnvService`, `HealthCheckService` |
| File Manager | `file_manager.php` | `files.view` (tulis: `files.manage`) | `FileManagerService` |
| Database | `databases.php` | `database.view` | `DatabaseService` |
| Domain | `domains.php` | `domain.manage` | `DomainService`, `SSLService` |
| Cron Jobs | `cron.php` | `cron.view` | `CronService` |
| Backup | `backups.php` | `backup.view` | `BackupService` |
| Log | `logs.php` | `logs.view` | `LogService` |
| Cloudflare Tunnel | `cloudflare.php` | `monitoring.view` | `CloudflareService` |
| Manajemen User | `users.php` | `users.manage` | `UserService` |
| Pengaturan | `settings.php` | `settings.manage` | — (tabel `settings`) |

> Kolom "File halaman" di atas adalah nama file `.php` sesungguhnya di
> `panel-src/public/`, tapi URL yang dipakai admin **tidak** pakai
> ekstensi (`/dashboard`, bukan `/dashboard.php`) — Nginx men-`rewrite`
> URL tanpa ekstensi ke file `.php`-nya secara internal, dan me-301-kan
> link `.php` lama ke bentuk bersihnya (lihat `module_panel_nginx_vhost`
> di `modules/panel.sh`). Sesi yang habis (idle timeout atau belum login
> sama sekali) sekarang juga mengingat halaman yang sedang dibuka —
> setelah login ulang, otomatis kembali ke situ, bukan selalu ke
> Dashboard (`Auth::requireLogin()`/`enforceSessionPolicy()` di
> `auth.php`, param `?redirect=`).

## Dashboard

Ringkasan sistem real-time: status service inti (`SystemService::
serviceStatuses()` — nginx, mariadb, php-fpm, dst lewat `service-status`
subcommand di panel-exec.sh), jumlah aplikasi Node.js yang sedang running
(`nodejsRunningCount()` — dihitung dari `pm2 jlist` live, bukan tabel DB),
dan statistik umum (`summary()`). Data live disegarkan lewat polling AJAX
(`public/ajax_stats.php`, `public/ajax_pm2.php`) tanpa reload halaman.

## Website PHP

CRUD website statis/PHP native, ditangani `NginxService`:

- `createWebsite($domain, $phpVersion, $userId, $gitRepoUrl, $gitBranch)` —
  membuat direktori document root (lewat `fs-mkdir-website`) **atau**,
  kalau URL Git diisi, `git clone --depth 1` langsung ke situ (lewat
  `git-clone-website` — repo harus punya folder `public/` sendiri di
  root-nya, konvensi Laravel/Symfony/dst, karena document root vhost
  selalu `<domain>/public`), menulis vhost Nginx (lewat
  `nginx-write-config` — divalidasi `nginx -t` sebelum diaktifkan, otomatis
  rollback ke config lama kalau invalid), lalu `nginx-enable`.
- `gitPull($id, $userId)` / `gitStatus($id)` — tombol Pull/Update di tabel
  Website (cuma muncul untuk situs hasil deploy Git): `git pull --ff-only`
  di tempat (gagal bersih, tidak pernah merge/rebase otomatis kalau ada
  konflik/divergen), dan info branch + commit hash + pesan commit terakhir
  yang ditampilkan di tabel.
- `toggleWebsite($id, $enable, $userId)` — enable/disable vhost tanpa
  menghapus file (`nginx-enable`/`nginx-disable`).
- `deleteWebsite($id, $deleteFiles, $userId)` — hapus vhost
  (`nginx-delete`), opsional sekalian hapus folder document root
  (`fs-remove-website`).
- `enableWildcard($id, $userId)` / `disableWildcard($id, $userId)` —
  Cloudflare for SaaS "Custom Hostname": website ini jadi `default_server`
  Nginx, menerima domain apa pun yang diarahkan ke port wildcard-nya.
  **Lebih dari satu situs (website maupun aplikasi Node.js) boleh
  mengaktifkan ini sekaligus** (sejak migration `2026072901`) — tiap slot
  dapat **port lokal sendiri** (`wildcard_port`, dipilih otomatis lewat
  `NginxService::findFreeWildcardPort()`: coba port 80 dulu untuk slot
  pertama/lama, baru rentang 8880-8979 untuk slot berikutnya), bukan
  selalu berbagi port 80 — Nginx memang cuma boleh punya satu
  `default_server` per `listen`, tapi itu artinya per PORT, bukan per
  server, jadi banyak port berbeda masing-masing boleh punya
  `default_server` sendiri. Slot non-80 sengaja `listen 127.0.0.1:<port>`
  (bukan semua-interface) karena satu-satunya jalur masuk yang
  dimaksudkan untuk slot itu adalah Tunnel Cloudflare-nya sendiri yang
  jalan lokal — beda dari slot port-80 yang memang publik (perilaku lama,
  dipertahankan apa adanya). `NginxService::wildcardSlots()` daftar semua
  slot aktif lintas kedua tabel. Aplikasi/website customer sendiri yang
  harus baca header `Host` untuk tahu ini request tenant mana — panel
  tidak ikut campur di situ.

  **Wajib untuk deployment mode `tunnel`/`hybrid`**: kalau Fallback Origin
  Custom Hostname kamu adalah domain yang di-route lewat Cloudflare
  Tunnel (bukan IP publik langsung), Tunnel-nya sendiri **wajib** punya
  **Catch-All Rule** yang diarahkan ke `http://127.0.0.1:<port slot
  ini>` (untuk slot pertama, sama dengan route domain panel/app biasa:
  `http://127.0.0.1:80`) — bukan dibiarkan default (`http_status:404`).
  Ini gara-gara cloudflared mencocokkan request berdasarkan header `Host`
  yang benar-benar dikirim, dan trafik Custom Hostname SaaS diteruskan
  Cloudflare dengan `Host` = domain custom milik tenant (bukan domain
  Fallback Origin kamu) — jadi tidak akan pernah cocok dengan rule
  hostname eksplisit mana pun, selalu jatuh ke Catch-All. Tanpa ini,
  semua request Custom Hostname langsung dapat 404 instan dari Tunnel
  sendiri, sebelum sempat menyentuh Nginx/app sama sekali (tidak akan
  muncul di log Nginx maupun `journalctl -u cloudflared`, jadi gampang
  disangka masalah di panel padahal bukan). Dashboard Cloudflare versi
  baru tidak lagi expose Catch-All Rule lewat UI "Add route" — harus
  di-set lewat API `PUT
  /accounts/{account_id}/cfd_tunnel/{tunnel_id}/configurations`,
  menambahkan satu entri di akhir array `ingress` **tanpa** field
  `hostname`. Lihat [Troubleshooting](Troubleshooting.md) untuk contoh
  perintahnya.

  **Lebih dari satu SaaS/slot wildcard sekaligus** — riset ke dokumentasi
  resmi Cloudflare (bukan tebakan, lihat sumber di bawah) memastikan:
  Host header yang dilihat cloudflared untuk mencocokkan `ingress` rule
  **selalu** domain custom milik tenant, apa pun override "Custom Origin
  Server"/SNI yang di-set di Cloudflare for SaaS — jadi trafik dari SATU
  Tunnel yang sama, ke berapa pun banyak zone SaaS, akan selalu jatuh ke
  **Catch-All yang sama**. Satu-satunya cara memisahkan trafik dua "pool"
  SaaS yang berbeda adalah lewat **Tunnel Cloudflare yang benar-benar
  terpisah** (instance `cloudflared` kedua/ketiga/dst, bukan cuma ingress
  rule tambahan di Tunnel yang sama) — masing-masing Tunnel independen
  boleh punya Catch-All Rule sendiri, diarahkan ke port slot wildcard yang
  berbeda-beda di panel ini. Panel **belum** mengotomasi pembuatan Tunnel
  tambahan (modul `cloudflare.sh` cuma pasang satu service `cloudflared`
  via token) — untuk slot kedua dst, admin perlu manual:
  1. Zero Trust Dashboard > Networks > Tunnels > Create a tunnel (dapat
     token baru).
  2. Aktifkan wildcard di panel untuk situs yang diinginkan, catat port
     yang di-assign (ditampilkan di tab Domain & SSL/Domain).
  3. Install `cloudflared` instance kedua secara manual di server (unit
     systemd baru, token file terpisah dari
     `/etc/cloudflared/tunnel.env` yang sudah dipakai instance pertama)
     dijalankan dengan token dari Tunnel baru itu.
  4. Set Catch-All Rule Tunnel baru itu ke `http://127.0.0.1:<port slot>`
     lewat API (sama caranya seperti Tunnel pertama).
  5. Arahkan Fallback Origin zone SaaS kedua ke domain yang di-CNAME ke
     Tunnel baru itu (bukan Tunnel pertama).

Sumber riset: [Configuring Cloudflare for SaaS](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/getting-started/),
[Custom origin server](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/custom-origin/)
(host header tetap domain custom tenant meski origin di-override),
[Tunnel ingress configuration](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/local-management/configuration-file/)
(pencocokan ingress rule berdasarkan HTTP Host header, bukan SNI).

Setiap website terikat ke satu versi PHP-FPM tertentu (`php_version`,
kolom di tabel `websites`) — pool PHP-FPM per versi sudah disiapkan saat
instalasi ([Instalasi](Instalasi.md) tahap 6).

### Settings (popup, sama pola dengan Node.js Apps)

Klik ikon gear di baris website membuka modal berisi iframe, pola identik
dengan Settings Node.js Apps (`partials/embed_header.php`/`embed_footer.php`,
`partials/website_settings_nav.php` jadi sidebar vertikal di dalam modal /
btn-group horizontal di mode halaman penuh). Lima sub-tab:

- **Umum** (`website_settings.php`) — edit Domain/Versi PHP/Document Root,
  sebelumnya sama sekali tidak bisa (satu-satunya cara ganti domain dulu
  cuma hapus lalu buat ulang website). `NginxService::updateWebsite()`
  menulis config situs BARU dulu (tervalidasi `nginx -t`) sebelum config
  lama dihapus, jadi config yang ditolak tidak pernah meninggalkan situs
  tanpa apa pun yang melayaninya. Mengganti Domain **tidak** memindahkan
  file di server (Document Root diedit terpisah, sengaja tidak diikat ke
  folder domain tertentu — bisa arahkan ke folder situs lain) dan otomatis
  menonaktifkan SSL (sertifikat lama tidak valid untuk domain baru,
  terbitkan ulang lewat tab Domain & SSL).
- **Domain & SSL** (`website_domains.php`) — `NginxService::addDomain()`/
  `removeDomain()`/`listDomains()`, pola sama persis dengan multi-domain
  Node.js Apps: tiap domain tambahan dapat situs Nginx sendiri, semuanya
  melayani Document Root & versi PHP yang sama dengan domain primary.
  Domain primary tidak bisa dihapus dari sini (harus lewat ganti Domain di
  tab Umum, atau hapus seluruh website). SSL per-domain (`SSLService`) dan
  Wildcard Hostname juga pindah ke sini (sebelumnya masing-masing modal
  terpisah/halaman `/domains?website_id=`).
- **Traffic & Rewrite** (`website_advanced.php`) — Default Index, Custom
  URL Rewrite (baris `rewrite` Nginx mentah, disisipkan langsung ke
  config — validasinya murni `nginx -t`, bukan whitelist syntax, karena
  ini fitur admin-trusted), Redirect (alihkan seluruh situs), Traffic
  Control (rate limit per-IP, lihat di bawah), Reverse Proxy (path prefix
  → target URL, repeatable, tabel `website_reverse_proxies`), dan Hotlink
  Protection (lihat di bawah). Semua berlaku untuk SELURUH domain website
  ini sekaligus — `NginxService::regenerateAllConfigs()` menulis ulang
  config setiap domain terdaftar tiap kali salah satu pengaturan ini
  berubah.

  **Traffic Control**: `limit_req_zone` Nginx cuma valid di context
  `http{}`, tidak bisa per-`server{}` — tiap situs yang mengaktifkan ini
  dapat satu file bersama `/etc/nginx/conf.d/panel-rate-limits.conf`
  (auto-include bawaan nginx.conf stok Debian/Ubuntu), full di-generate
  ulang dari SEMUA situs yang rate-limit-nya aktif tiap kali ada
  perubahan (`nginx_build_rate_limit_zones_config()`). Nama zone di-hash
  dari domain (`nginx_rate_limit_zone_name()`, MD5 12 karakter) — sengaja
  bukan sekadar ganti karakter non-alnum jadi underscore, karena dua
  domain berbeda seperti `a.b.com` dan `a-b.com` bisa collide jadi nama
  zone yang sama dengan skema sederhana itu.

  **Hotlink Protection**: `valid_referers` Nginx pada `location` khusus
  ekstensi file yang dilindungi — akses tanpa header Referer (curl/akses
  langsung) selalu diizinkan, cuma embed dari domain lain yang diblokir.

  **Keamanan validasi**: Document Root dan target URL (Redirect/Reverse
  Proxy) dibatasi charset ketat (`Validator::documentRoot()`/`targetUrl()`)
  karena diselipkan langsung ke directive Nginx (`root .../`,
  `proxy_pass .../`) — tanpa ini, sebuah nilai yang mengandung `;`/newline
  bisa menyuntik directive Nginx tambahan di luar yang dimaksud (beda dari
  `custom_rewrite_rules` yang MEMANG sengaja raw passthrough).
- **Traffic Analysis** (`website_traffic.php`) — request/hari dari access
  log Nginx domain primary, termasuk log yang sudah dirotasi (`.1` +
  `.gz`, lewat operasi `log-traffic-daily`/`op_log_traffic_daily` yang
  parsing `awk` atas format `combined` bawaan Nginx). Bukan real-time/SSE
  (data harian, push tiap detik tidak ada gunanya) — grafik batang CSS
  polos, tanpa library chart.
- **Backup** (`website_backup.php`) — trigger `BackupService::backupWebsite()`
  + riwayat backup terfilter untuk domain ini saja (sebelumnya tombol
  "Backup Sekarang" langsung di tabel utama, riwayatnya cuma bisa dilihat
  gabung semua situs di Pengaturan > Backup & Restore).

## Node.js Apps

Bagian paling kompleks, ditangani `NodeService`. Prinsip inti: **status
runtime selalu dari `pm2 jlist` langsung**, tabel `nodejs_apps` cuma
metadata (lihat [Arsitektur](Arsitektur.md) pilar #2).

- `createApp(...)` — validasi port bebas (`isPortAvailable`/
  `findFreePort`, range default 3000-3999, dicek juga lewat `port-check`
  di server), menulis `ecosystem.config.js` PM2 (lewat `pm2-deploy`,
  dijalankan sebagai user `nodeapps`), lalu `pm2 save` supaya bertahan
  setelah reboot. `build_command` **tidak** dijalankan saat create (folder
  proyek masih kosong, baru diisi lewat File Manager/git setelahnya) —
  baru benar-benar jalan di redeploy berikutnya.
- `updateApp(...)` — edit konfigurasi (Start/Build Command, NODE_ENV,
  Instances, Exec Mode, Autorestart, Watch, Max Memory Restart, versi
  Node.js) lewat tab **Settings > Umum**, langsung redeploy PM2 dengan
  config baru. Versi Node.js dan Build Command sekarang **benar-benar
  dipakai saat deploy** (`nvm use <versi>` sebelum `pm2 start`; Build
  Command dijalankan di folder app sebagai user `nodeapps`) — sebelumnya
  cuma metadata tampilan.
- `controlApp($id, $action, $userId)` — start/stop/restart/reload/**reset
  restart counter** lewat subcommand `pm2-start`/`pm2-stop`/`pm2-restart`/
  `pm2-reload`/`pm2-reset`. Di UI, kelima aksi ini muncul lewat klik pada
  kolom Status (bukan tombol terpisah) — popup kecil berisi ikon, posisinya
  dihitung manual dari tombol yang diklik (bukan Bootstrap Dropdown, yang
  ternyata tidak reliable diposisikan di dalam tabel yang bisa di-scroll).
- `addDomain($id, $domain, $userId)` / `removeDomain(...)` /
  `listDomains($id)` — satu app boleh punya lebih dari satu domain, masing-
  masing situs Nginx sendiri (`node-<domain>.conf`) tapi proxy ke port yang
  sama. Domain pertama yang ditambahkan jadi "primary" (kolom `domain` di
  `nodejs_apps`, ditampilkan di tabel utama).
- `enableWildcard($id, $userId)` / `disableWildcard(...)` — sama seperti
  Website PHP di atas: Cloudflare for SaaS Custom Hostname, boleh lebih
  dari satu slot aktif lintas tabel `websites`+`nodejs_apps`, tiap slot
  dapat port lokal sendiri (lihat penjelasan lengkap + cara setup Tunnel
  tambahan di bagian Website PHP).
- `combinedStatus()` — gabungan data `pm2 jlist` (runtime) + tabel
  `nodejs_apps` (metadata) untuk ditampilkan di UI.
- `importUnmanaged($pm2Name, $userId)` — mengambil alih proses PM2 yang
  sudah berjalan tapi belum terdaftar di panel (misalnya dideploy manual
  sebelumnya) ke dalam pencatatan panel.
- `deleteApp($id, $deleteFiles, $userId)` — `pm2-delete` + hapus semua
  domain (bukan cuma primary) + opsional hapus folder aplikasi
  (`fs-remove-nodeapp`).
- `getLogs($id, $lines)` / `clearLogs($id)` — baca langsung dari file log
  live (bukan `pm2 logs`, lihat subbagian **Log per-run & real-time** di
  bawah) / `pm2-flush`. Ecosystem config selalu set `merge_logs: true`
  supaya log cluster mode (>1 instances) tidak tercecer ke file terpisah
  per-worker.

### Log per-run & real-time (`nodejs_logs.php`)

`out_file` dan `error_file` di ecosystem config **sengaja** diarahkan ke
path yang SAMA (`/home/nodeapps/.pm2/logs/<pm2_name>.log`) — bukan default
PM2 (`<name>-out.log`/`<name>-error.log` terpisah) — supaya stdout+stderr
bercampur di satu file sesuai urutan waktu, dan supaya panel bisa baca
langsung dari file itu alih-alih lewat `pm2 logs` (yang menambahkan prefix
`<id>|<nama> |` di tiap baris, berguna kalau memantau banyak app sekaligus
di satu terminal, cuma jadi noise di sini karena selalu cuma satu app yang
ditampilkan).

Setiap `start`/`restart`/`reload`/deploy ulang (`rotate_pm2_log()` di
`panel-exec.sh`) memindahkan isi file live yang sedang berjalan ke arsip
`<pm2_name><YYYYMMDDHHMMSSmmm 17-digit>.log`, lalu **mengosongkan** (bukan
menghapus/rename) file live-nya di tempat — PM2 tetap memegang file
descriptor yang sama sejak proses itu start, rename akan membuat PM2 terus
menulis ke file yang sudah dipindah namanya (descriptor mengikuti inode,
bukan path), sedangkan truncate di tempat langsung membuat tulisan
berikutnya otomatis mulai dari offset 0 lagi tanpa perlu `pm2 reloadLogs`
(yang efeknya ke SEMUA app di daemon PM2 yang sama, bukan cuma satu).
Dropdown "Run sebelumnya" di halaman Logs membaca daftar arsip ini
(`pm2-logs-list`) dan bisa membuka isinya (`pm2-logs-read-archive`) sebagai
tampilan statis (bukan live).

Log saat ini (bukan arsip) tampil **real-time** lewat Server-Sent Events
(`nodejs_logs_stream.php`, lihat `app/helpers/sse.php`) — baris baru
otomatis muncul tanpa refresh manual. Stream berbasis offset byte
(`pm2-logs-tail`), jadi cuma konten BARU yang dikirim tiap tick (bukan
tail ulang seluruh file), dan koneksi sengaja diputus paksa tiap ~55 detik
lalu disambung ulang otomatis dari sisi klien (bukan mengandalkan
auto-reconnect bawaan `EventSource`, yang akan meminta ulang URL awal apa
adanya dan menyebabkan baris lama terkirim dobel) — supaya satu worker
PHP-FPM tidak tertahan tanpa batas waktu.

**App yang sudah dideploy SEBELUM update ini** masih menulis ke path log
lama (default PM2) sampai di-redeploy ulang — buka **Settings > Umum**
app tersebut lalu **Simpan** (tanpa perlu ubah apa pun) untuk memicu
`pm2-deploy` ulang dan pindah ke path log baru.

Statistik CPU/RAM/Uptime/Restart di tabel Node.js Apps dan widget
Dashboard juga **real-time**, tapi lewat polling `/ajax_pm2` tiap 3 detik
(bukan SSE — cukup untuk angka yang berubah pelan, dan memakai ulang pola
`data-refresh-url`/`panel:refresh` yang sudah ada, lihat `app.js`'s
`PanelNodeStats.apply()`), bukan Server-Sent Events seperti log.

### Settings (popup, bukan halaman terpisah)

Klik ikon gear di baris aplikasi membuka **modal berisi iframe**, bukan
navigasi ke halaman baru — pola yang sama dengan "Open in Terminal" di
File Manager. Halaman aslinya (`nodejs_settings.php`, `nodejs_domains.php`,
`nodejs_env.php`, `nodejs_logs.php`, `nodejs_health.php`,
`nodejs_backup.php`) tetap ada sebagai file terpisah dan tetap bisa diakses
langsung (mode non-popup, misal lewat bookmark) — saat dibuka dengan
`?embed=1` (dari dalam iframe), halaman merender versi ringkas: tanpa
sidebar/topbar panel (`partials/embed_header.php`/`embed_footer.php`), dan
navigasi antar-tab (`partials/nodejs_settings_nav.php`) jadi sidebar
vertikal di kiri, bukan baris tombol horizontal di atas.

### Environment Variables (`nodejs_env.php`)

Dikelola per-aplikasi lewat `EnvService`. Nilai disimpan **terenkripsi**
di tabel `app_env_variables` (`var_value_enc`, AES-256-GCM, kunci dari
`APP_KEY` di `.env` — lihat [Keamanan](Keamanan.md)). Nilai secret
disamarkan (`••••••••`) di UI dengan tombol show/hide, mendukung
import/export format `.env` (`parseDotEnv()`/`toDotEnvExport()`).
Perubahan baru berlaku setelah menekan **Terapkan & Restart** (menulis
ulang `ecosystem.config.js` lalu `pm2 start ... --update-env`).

### Health Check (`nodejs_health.php`)

`HealthCheckService` — cek HTTP periodik per aplikasi (GET/HEAD/POST) lewat
cURL PHP langsung (**tidak** lewat shell, URL tidak pernah menyentuh
command line). Status: `healthy` / `unhealthy` / `timeout` /
`connection_refused` / `unknown`. Dijalankan oleh
`scripts/health_check_runner.php` lewat cron sistem `* * * * *`
(`runDueChecks()` — hanya menjalankan yang sudah lewat interval-nya).
Murni informasional; **PM2 tetap satu-satunya sumber kebenaran** untuk
apakah proses benar-benar hidup.

## File Manager

`FileManagerService`, akses via sidebar (`/file_manager`) atau tombol
di baris Website PHP/Node.js Apps. Browse/upload/download/edit/rename/
copy/move/hapus/chmod/search, semua lewat `panel-exec.sh` (`files-*`
subcommand) karena `panel` tidak bisa baca file milik `www-data`/
`nodeapps` langsung. Editor teks inline pakai CodeMirror (vendor lokal,
bukan CDN), auto-pilih mode syntax dari ekstensi file.

**Extract & Compress** — klik-kanan file `.zip` yang sudah ada di disk
untuk **Ekstrak** (ke folder baru di sebelahnya, nama = nama ZIP tanpa
ekstensi, ada pengecekan zip-slip) — beda dari "Upload & Extract ZIP" yang
mengekstrak ZIP yang baru di-upload (`files-extract-zip`), ini
(`files-extract`) untuk ZIP yang **sudah ada di server**. Pilih beberapa
file/folder (maks 100 item) lalu **Kompres** di toolbar bulk-action untuk
bikin ZIP baru (`files-compress`) dari item-item tersebut.

**Recycle Bin (soft-delete)** — hapus file/folder tidak langsung `rm -rf`,
tapi dipindah ke `.trash/` tersembunyi di dalam scope
(`files-trash-list`/`-restore`/`-delete`/`-empty`), bisa dipulihkan ke
lokasi asalnya.

Dua mode scope:

- **Per-site/app** (`scope=website|nodeapp`) — dikunci ke satu
  `document_root`/`project_path` spesifik, dipanggil dari baris
  Website PHP/Node.js Apps.
- **Jelajahi Semua** (`scope=www|nodeapps`, ala Explorer) — root langsung
  ke seluruh `/var/www` atau `/home/nodeapps/apps`, termasuk folder yang
  belum terdaftar sebagai website/app di panel. Dipilih dari sidebar tanpa
  perlu klik website/app tertentu dulu.

**Proteksi khusus mode Jelajahi Semua**: hapus dan rename **ditolak** untuk
entri level-teratas (folder website/app itu sendiri) — mencegah situs
terhapus lewat File Manager tanpa lewat alur "Hapus Website"/"Hapus
Aplikasi" yang semestinya (yang juga membersihkan baris database & config
Nginx/PM2 terkait). File/folder **di dalam** situs tetap bebas dikelola di
kedalaman berapa pun.

## Database

`DatabaseService`, dua koneksi terpisah (lihat
[Arsitektur](Arsitektur.md#dua-koneksi-database-pdo)):

- `listLive()` — daftar database MariaDB sesungguhnya di server (lewat
  koneksi `panel_provisioner`).
- `createDatabase($dbName, $dbUser, $password, $note, $userId)` — membuat
  database + user MariaDB baru, `GRANT` scoped ke database itu saja,
  dicatat di tabel `databases_registry`.
- `dropDatabase($registryId, $userId)` — hapus database + user terkait.

## Domain

`DomainService` — registry gabungan domain untuk website PHP maupun
aplikasi Node.js (tabel `domains`, kolom `type` ENUM `php`/`nodejs`,
`website_id` XOR `nodejs_app_id`):

- `setCloudflareProxied($id, $proxied, $userId)` — menandai domain
  di-proxy lewat Cloudflare (kolom `cloudflare_proxied`) — status
  penanda saja, tidak mengubah konfigurasi Cloudflare dari sisi panel.
- `toggle($id, $enable, $userId)` — enable/disable domain.
- SSL per-domain ditangani `SSLService::issueForDomain()` /
  `removeCertificate()` lewat Certbot mode webroot (`certbot-issue`/
  `certbot-remove` di panel-exec.sh) — tidak berlaku di mode deployment
  `tunnel` murni (lihat [Cloudflare Tunnel](Cloudflare-Tunnel.md)).

## Cron Jobs

`CronService` — jadwal terjadwal per website PHP atau aplikasi Node.js,
ditulis sebagai file diskrit `/etc/cron.d/panel-<id>` (**bukan** mengedit
crontab bersama di tempat), lewat subcommand `cron-write`/`cron-delete`.

Tiga `command_type` yang didukung, masing-masing dibangun dari template
tetap (bukan string bebas dari user):

| `command_type` | Command yang dibangun | User eksekusi |
|---|---|---|
| `php_artisan` | `php{version} {siteRoot}/artisan schedule:run` | `www-data` |
| `php_script` | `php{version} {siteRoot}/{command_arg}` | `www-data` |
| `node_script` | `node {command_arg}` (dengan NVM di-load) | `nodeapps` |

`command_arg` untuk `php_script`/`node_script` divalidasi
`Validator::relativeScriptPath()` (charset `[a-zA-Z0-9_./-]` saja, tanpa
`..`) sebelum disimpan — mencegah command injection maupun path traversal
lewat argumen ini.

## Backup

`BackupService` — tiga jenis backup, semuanya lewat `panel-exec.sh` karena
`panel` tidak punya akses baca langsung ke file `www-data`/`nodeapps`, dan
`mysqldump` perlu jalan sebagai `root` (unix_socket auth):

| Jenis | Method | Subcommand |
|---|---|---|
| Database | `backupDatabase($dbName, $userId)` | `mysqldump-db` |
| Website | `backupWebsite($domain, $userId)` | `backup-tar-website` |
| Aplikasi Node.js | `backupNodeApp($appName, $userId)` | `backup-tar-nodeapp` |

`restore($backupId, $userId)` — **sebelum** melakukan restore, panel
otomatis membuat backup baru dari kondisi saat itu terlebih dahulu,
sehingga restore selalu bisa dibatalkan/diulang. Semua file backup
tersimpan di `/opt/server-panel/storage/backups/`, path selalu divalidasi
`require_path_within()` di sisi bash agar tidak bisa keluar dari direktori
itu.

## Log

`LogService` — tail (bukan `tail -f`, snapshot statis, maks baris dipaksa
server-side) untuk beberapa sumber log lewat whitelist `logkey` di
`panel-exec.sh` (`log-tail`/`log-clear`):

- `nginxAccess($domain)` / `nginxError($domain)` — `/var/log/nginx/
  {domain}-access.log` / `-error.log`.
- `phpFpmError($phpVersion)` — `/var/log/php{version}-fpm.log` (versi
  dibatasi whitelist 7.4–8.4).
- `deploymentLog()` — `/var/log/yuuka-installer/deployment.log`.
- `panelAppLog()` — log aplikasi panel sendiri (`app-error.log` di
  `storage/logs/`, dibaca langsung karena berada dalam `open_basedir`
  panel, tidak perlu lewat `panel-exec.sh`).

## Cloudflare Tunnel

Lihat halaman khusus: [Cloudflare Tunnel](Cloudflare-Tunnel.md).

## Manajemen User

`UserService`, permission `users.manage` (admin only):

- `create($username, $email, $password, $role, $actingUserId)` — password
  di-hash `PASSWORD_BCRYPT`.
- `changeRole($userId, $role, $actingUserId)`, `setActive($userId, $active,
  $actingUserId)` (nonaktifkan tanpa hapus), `changePassword(...)`,
  `delete($userId, $actingUserId)`.

Semua aksi di atas dicatat ke `activity_log`. Untuk skenario "lupa
password saat tidak bisa login sama sekali", lihat
[Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) (dilakukan langsung lewat
database, bukan lewat UI ini).

## Pengaturan

Key/value sederhana di tabel `settings` — daftar lengkap kunci di
[Skema Database § settings](Skema-Database.md#settings). Beberapa yang
sebelumnya cuma bisa diubah lewat `.env`/SSH sekarang bisa lewat
Pengaturan > Umum, jadi admin tidak wajib turun ke terminal untuk hal
non-krusial: `session_idle_timeout`/`session_lifetime` (override
`SESSION_IDLE_TIMEOUT`/`SESSION_LIFETIME` di `.env`) dan
`filemanager_max_upload_mb` (override `FILEMANAGER_MAX_UPLOAD_MB`, maks
512 MB mengikuti `client_max_body_size` vhost panel) — pola yang sama
dipakai kalau mau menambah setting lain yang boleh diubah dari UI:
tambahkan kuncinya ke `SettingsService::KNOWN_KEYS`, baca dengan
`SettingsService::get($key) ?: Config::getInt(...)` (DB override
`.env` default).
