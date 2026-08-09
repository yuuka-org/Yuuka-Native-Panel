# Skema Database

[← Kembali ke Home](Home.md)

Database MariaDB `server_panel` (nama sesungguhnya = `DB_DATABASE` di
`.env`, default `server_panel`), charset `utf8mb4`, seluruh tabel InnoDB.
Diimpor otomatis oleh `modules/mariadb.sh` saat instalasi (idempotent —
hanya jalan kalau database masih kosong). Sumber: `sql/schema.sql`.

## `panel_users`

Administrator/operator panel (RBAC).

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `username` | VARCHAR(64) UNIQUE | |
| `email` | VARCHAR(190) UNIQUE | |
| `password_hash` | VARCHAR(255) | `password_hash($pw, PASSWORD_BCRYPT)` — selalu diawali `$2y$` |
| `role` | ENUM admin/operator/developer/viewer | default `viewer` |
| `is_active` | TINYINT(1) | default 1 |
| `last_login_at`, `last_login_ip` | | |

Lihat [Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) untuk cara reset
manual lewat tabel ini.

## `login_attempts`

Rate limiting brute force (5x gagal / 15 menit per IP+username, ditegakkan
di `panel-src/app/helpers/auth.php`).

| Kolom | Keterangan |
|---|---|
| `username`, `ip_address`, `success` | |
| `attempted_at` | Index gabungan `(ip_address, attempted_at)` dan `(username, attempted_at)` untuk lookup cepat |

## `activity_log`

Audit trail semua aksi state-changing di panel.

| Kolom | Keterangan |
|---|---|
| `user_id` | FK ke `panel_users`, `ON DELETE SET NULL` (log tetap ada walau user dihapus) |
| `action`, `description`, `ip_address`, `created_at` | |

## `websites`

Website PHP native/multi-versi.

| Kolom | Keterangan |
|---|---|
| `domain` | UNIQUE |
| `php_version` | Versi PHP-FPM yang dipakai vhost ini |
| `document_root`, `nginx_conf_name` | |
| `git_repo_url`, `git_branch` | NULL kalau bukan deployment Git — diisi kalau website dibuat dari `git clone` (lihat `NginxService::createWebsite()`/`gitPull()`/`gitStatus()`) |
| `wildcard_enabled` | Cloudflare for SaaS Custom Hostname — situs ini jadi `default_server` Nginx, menerima domain apa pun. Cuma satu situs (website ATAU aplikasi Node.js) yang boleh bernilai 1 di seluruh server (`NginxService::wildcardHolder()`) |
| `is_enabled`, `ssl_enabled` | |
| `default_index`, `custom_rewrite_rules` | Settings > Default Index / URL Rewrite |
| `rate_limit_enabled`, `rate_limit_rps`, `rate_limit_burst` | Settings > Traffic Control - request/detik per IP |
| `max_conn_total`, `max_conn_per_ip`, `max_bandwidth_kbps` | Settings > Traffic Control - 0 berarti tanpa batas untuk ketiganya. `max_conn_total`/`max_conn_per_ip` butuh `limit_conn_zone` bersama (lihat `website_reverse_proxies`/rate-limit-zones di [Fitur Panel](Fitur-Panel.md)), `max_bandwidth_kbps` murni `limit_rate` per-lokasi |
| `hotlink_protection_enabled`, `hotlink_extensions`, `hotlink_allowed_referrers` | Settings > Hotlink Protection - `hotlink_extensions` comma-separated (bukan pipe lagi, lihat migrasi `2026080902`) |
| `hotlink_response_code`, `hotlink_allow_empty_referer` | Settings > Hotlink Protection - kode HTTP saat hotlink terdeteksi (default 403) dan apakah request tanpa header Referer sama sekali diizinkan (default ya) |
| `created_by` | FK `panel_users`, `ON DELETE SET NULL` |

**Catatan**: `redirect_enabled`/`redirect_target` (sitewide, satu redirect
per situs) sudah **dihapus** (migrasi `2026080903`) — Redirect sekarang
per-domain, lihat tabel `website_redirects` di bawah.

## `website_redirects`

Redirect per-domain (Settings > Website > Redirect) - **satu rule per
domain**, bukan sitewide seperti sebelumnya, supaya satu situs bisa
redirect satu domain alias sementara domain lain di situs yang sama tetap
melayani konten asli.

| Kolom | Keterangan |
|---|---|
| `website_id` | FK `websites`, `ON DELETE CASCADE` |
| `source_domain` | Harus salah satu domain milik website ini (primary atau tambahan). UNIQUE per `website_id` |
| `target_url`, `status_code` (301/302/307/308) | |
| `include_uri_params` | Aktif: path+query asli ditempel di belakang `target_url` (`$request_uri`). Nonaktif: selalu redirect persis ke `target_url` |

## `website_reverse_proxies`

Reverse Proxy per-website (Settings > Website > Reverse Proxy), repeatable
(banyak rule per situs).

| Kolom | Keterangan |
|---|---|
| `website_id` | FK `websites`, `ON DELETE CASCADE` |
| `name` | Label bebas, boleh NULL |
| `path_prefix`, `target_url` | UNIQUE `path_prefix` per `website_id` |
| `websocket_enabled` | Default aktif - pilih snippet Nginx `proxy-params.conf` (header Upgrade/Connection) vs `proxy-params-basic.conf` (tanpanya) |
| `cache_enabled` | `proxy_cache panel_proxy_cache;` - satu cache pool BERSAMA lintas situs (`/etc/nginx/conf.d/panel-proxy-cache.conf`, dideklarasikan sekali oleh installer), bukan per-rule |
| `send_domain` | Header `Host` custom ke upstream, default literal `$host` (nginx resolve sendiri saat runtime) |
| `show_proxy_path` | Aktif (default): path diteruskan apa adanya. Nonaktif: prefix path dipangkas (dikendalikan lewat ada/tidaknya trailing slash pada `proxy_pass`) |

## `website_limit_access`

Basic Auth per path (Settings > Website > Limit Access, tab "Limit
Access").

| Kolom | Keterangan |
|---|---|
| `website_id` | FK `websites`, `ON DELETE CASCADE` |
| `name`, `path_prefix` | UNIQUE `path_prefix` per `website_id` |
| `username`, `password_hash` | `password_hash` bcrypt (`PASSWORD_BCRYPT`, sama skema `panel_users`) - juga ditulis ke berkas htpasswd sesungguhnya yang dibaca Nginx (`/etc/nginx/htpasswd/la-<id>.htpasswd`, satu berkas per rule) |

## `website_deny_rules`

Blokir ekstensi file per path (Settings > Website > Limit Access, tab
"Deny Access").

| Kolom | Keterangan |
|---|---|
| `website_id` | FK `websites`, `ON DELETE CASCADE` |
| `name`, `path_prefix` | UNIQUE `path_prefix` per `website_id` |
| `suffixes` | Comma-separated tanpa titik (mis. `php,jsp,sh`), input tag-chip di UI |

## `nodejs_apps`

**Metadata saja** — status runtime (CPU/RAM/uptime/status) **selalu**
dibaca live dari `pm2 jlist`, bukan dari tabel ini (lihat
[Arsitektur](Arsitektur.md)).

| Kolom | Keterangan |
|---|---|
| `pm2_name` | UNIQUE, nama proses di PM2 |
| `domain`, `project_path`, `node_version`, `port` (UNIQUE) | `domain` = domain "primary" (ditampilkan di tabel utama) — app bisa punya domain tambahan lewat tabel `domains`, lihat `NodeService::addDomain()`/`listDomains()` |
| `start_command`, `build_command` | `build_command` dijalankan (sebagai user `nodeapps`, di folder app) setiap redeploy lewat tab Settings > Umum — **kecuali** saat create pertama kali (folder masih kosong) |
| `instances`, `exec_mode` (fork/cluster), `autorestart`, `watch`, `max_memory_restart` | Parameter `ecosystem.config.cjs`. Ecosystem juga selalu set `merge_logs: true` supaya log multi-instance (cluster mode) tidak tercecer ke file terpisah per-worker |
| `node_env` | default `production` |
| `wildcard_enabled` | Cloudflare for SaaS Custom Hostname — sama seperti `websites.wildcard_enabled`, satu slot untuk seluruh server (lintas tabel `websites`+`nodejs_apps`) |
| `is_managed` | Membedakan app yang dikelola penuh vs. hasil `importUnmanaged()` |
| `last_known_status` | **Historis/audit only** — komentar di skema eksplisit menyebut ini bukan sumber kebenaran runtime |

Versi Node.js per-app **benar-benar dipakai saat deploy** (`nvm use
<node_version>` sebelum `pm2 start`/`pm2 restart` di `op_pm2_deploy`,
lihat [Referensi panel-exec.sh](Panel-Exec-Reference.md)) — bukan cuma
metadata tampilan.

## `app_env_variables`

Environment variable per aplikasi Node.js, nilai **terenkripsi**.

| Kolom | Keterangan |
|---|---|
| `app_id` | FK `nodejs_apps`, `ON DELETE CASCADE` |
| `var_key` | UNIQUE per `app_id` |
| `var_value_enc` | AES-256-GCM, kunci dari `APP_KEY` di `.env` (lihat `EnvService`) |
| `is_secret` | Menentukan apakah disamarkan (`••••••••`) di UI |

## `databases_registry`

Database tenant yang diprovisikan lewat panel (bukan seluruh database di
server — hanya yang dibuat lewat menu Database).

| Kolom | Keterangan |
|---|---|
| `db_name` UNIQUE, `db_user`, `note` | |
| `created_by` | FK `panel_users` |

## `domains`

Registry domain gabungan — satu domain menunjuk ke **salah satu**
`website_id` atau `nodejs_app_id` (tidak keduanya).

| Kolom | Keterangan |
|---|---|
| `domain` UNIQUE | |
| `type` | ENUM `php` / `nodejs` |
| `website_id`, `nodejs_app_id` | FK, `ON DELETE CASCADE` |
| `ssl_enabled`, `cloudflare_proxied`, `is_enabled` | |

## `cron_jobs`

Lihat penjelasan lengkap command template di
[Fitur Panel § Cron Jobs](Fitur-Panel.md#cron-jobs).

| Kolom | Keterangan |
|---|---|
| `owner_type` | ENUM `php`/`nodejs` |
| `website_id`, `nodejs_app_id` | FK, `ON DELETE CASCADE` |
| `schedule` | Ekspresi cron 5 kolom |
| `command_type` | ENUM `php_artisan`/`php_script`/`node_script` |
| `command_arg` | **Path script relatif tervalidasi saja, bukan shell bebas** |
| `last_run_at`, `last_run_status` | |

## `backups`

| Kolom | Keterangan |
|---|---|
| `type` | ENUM `database`/`website`/`nodejs` |
| `target_name`, `file_path`, `size_bytes` | |
| `status` | ENUM `completed`/`failed`/`running` |
| `cloud_uploaded`, `cloud_uploaded_at`, `cloud_path` | Status upload ke S3-compatible. Diisi otomatis oleh `CloudBackupService::uploadIfConfigured()` (dipanggil dari `BackupService::finalize()`) setelah backup lokal sukses DAN Cloud Storage S3 aktif di Settings > Backup - lihat [Fitur Panel § Backup](Fitur-Panel.md) |
| `cloud_uploaded_gdrive`, `cloud_uploaded_gdrive_at`, `cloud_path_gdrive` | Sama seperti kolom S3 di atas tapi untuk target Google Drive - independen, satu backup bisa ter-upload ke S3, Google Drive, keduanya, atau tidak sama sekali |
| `created_by` | FK `panel_users` |

## `backup_schedules`

Jadwal backup otomatis berulang (Settings > Backup > Jadwal Backup) - satu baris per target (unik per `type`+`target_name`).

| Kolom | Keterangan |
|---|---|
| `type` | ENUM `database`/`website`/`nodejs` |
| `target_name` | Nama target (nama database/domain/nama aplikasi) |
| `interval_unit` | ENUM `minute`/`hour`/`day`/`month`/`year` |
| `interval_value` | Kelipatan satuan (mis. `interval_unit=hour`, `interval_value=6` = tiap 6 jam) |
| `is_enabled` | |
| `last_run_at`, `last_run_status` | Diisi oleh `backup_scheduler_runner.php` (cron tiap menit, lihat `modules/panel.sh`) setiap kali sebuah jadwal benar-benar dieksekusi |
| `created_by` | FK `panel_users` |

## `health_checks`

Satu health check per aplikasi Node.js (`UNIQUE KEY uq_health_app`),
murni informasional — lihat
[Fitur Panel § Health Check](Fitur-Panel.md#health-check-nodejs_healthphp).

| Kolom | Keterangan |
|---|---|
| `nodejs_app_id` | FK `nodejs_apps`, `ON DELETE CASCADE`, UNIQUE |
| `url`, `http_method` (GET/HEAD/POST) | |
| `timeout_seconds`, `interval_seconds` | |
| `last_status` | ENUM `healthy`/`unhealthy`/`timeout`/`connection_refused`/`unknown` |
| `last_status_code`, `last_response_ms`, `last_checked_at`, `failure_count` | |

## `settings`

Key/value sederhana (`SettingsService`) — kunci yang boleh ditulis dibatasi
whitelist `SettingsService::KNOWN_KEYS` (dipakai juga oleh Settings >
Migrate saat impor, menolak kunci di luar daftar ini).

| `setting_key` | Kegunaan |
|---|---|
| `deployment_mode` | Mode deployment aktif (direct/tunnel/hybrid) |
| `cpu_alert_threshold`, `mem_alert_threshold`, `restart_alert_threshold` | Ambang alert Dashboard (CPU %, RAM %, jumlah restart PM2) |
| `phpmyadmin_url` | URL phpMyAdmin (diisi otomatis saat instalasi modul phpMyAdmin, bisa diedit manual di Pengaturan) |
| `php_installed_versions`, `php_default_version` | Ditulis oleh installer/`update.sh`, bukan lewat UI |
| `panel_login_title`, `panel_login_logo` | Kustomisasi halaman login (Pengaturan > Page) |
| `session_idle_timeout`, `session_lifetime` | Override `.env` (`SESSION_IDLE_TIMEOUT`/`SESSION_LIFETIME`) lewat Pengaturan > Umum — kalau kosong/0, fallback ke nilai `.env` |
| `alarm_webhook_url`, `alarm_last_notified_at` | Notifikasi webhook Dashboard |
| `dashboard_widget_config` | Widget mana yang tampil di Dashboard |
| `security_entrance_path` | Path rahasia login — lihat [Model Keamanan](Keamanan.md) |
| `basicauth_enabled`, `basicauth_username` | Status BasicAuth di depan seluruh panel |
| `filemanager_max_upload_mb` | Override `.env` (`FILEMANAGER_MAX_UPLOAD_MB`) lewat Pengaturan > Umum, maks 512 (batas `client_max_body_size` vhost panel) |
| `backup_cloud_enabled`, `backup_cloud_endpoint`, `backup_cloud_region`, `backup_cloud_bucket`, `backup_cloud_access_key`, `backup_cloud_path_prefix` | Konfigurasi Cloud Storage S3-compatible (Settings > Backup) - `backup_cloud_endpoint` kosong berarti AWS S3 asli, diisi untuk provider lain (mis. Backblaze B2) |
| `backup_cloud_secret_key_enc` | Secret key S3, terenkripsi AES-256-GCM lewat `EnvService::encrypt()` (kunci `APP_KEY` yang sama dengan environment variable Node.js) - tidak pernah dikirim balik ke browser dalam bentuk plain |
| `backup_cloud_gdrive_enabled`, `backup_cloud_gdrive_client_id`, `backup_cloud_gdrive_folder_id`, `backup_cloud_gdrive_path_prefix` | Konfigurasi target Google Drive (Settings > Backup) - independen dari S3 di atas, bisa aktif berbarengan |
| `backup_cloud_gdrive_token_enc`, `backup_cloud_gdrive_client_secret_enc` | Token OAuth (hasil `rclone authorize "drive"` yang dijalankan admin sendiri) dan Client Secret opsional, terenkripsi sama seperti secret key S3 |

## Diagram Relasi (ringkas)

```
panel_users ──< activity_log
     │              │
     ├──< websites ──┼──< domains (type=php)
     │      │        │
     │      ├──< website_redirects
     │      ├──< website_reverse_proxies
     │      ├──< website_limit_access
     │      └──< website_deny_rules
     │
     ├──< nodejs_apps─┼──< domains (type=nodejs)
     │      │  │      │
     │      │  ├──< app_env_variables
     │      │  └──< health_checks
     │      │
     ├──< databases_registry
     ├──< cron_jobs >── websites / nodejs_apps
     ├──< backup_schedules
     └──< backups
```
