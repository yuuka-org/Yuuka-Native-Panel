# Konfigurasi (`.env` & State)

[← Kembali ke Home](Home.md)

## `.env` Panel

Lokasi di server: **`/opt/server-panel/.env`** (permission `600`, owner
`panel:panel`, ditulis sekali saat instalasi oleh `modules/panel.sh` —
**tidak pernah ditimpa** pada re-run installer). Template:
`panel-src/.env.example`.

| Key | Contoh/Default | Keterangan |
|---|---|---|
| `APP_NAME` | `Yuuka Server Panel` | |
| `APP_ENV` | `production` | |
| `APP_KEY` | (random, di-generate saat install) | Kunci enkripsi AES-256-GCM untuk `app_env_variables` (lihat `EnvService`) |
| `APP_URL` | `https://panel.example.com` | Di-sync otomatis ke `http://`/`https://` sesuai status SSL nyata - lihat baris `SESSION_SECURE_COOKIE` |
| `APP_DEPLOYMENT_MODE` | `direct` / `tunnel` / `hybrid` | Diisi sesuai pilihan mode saat instalasi |
| `DB_HOST` | `127.0.0.1` | |
| `DB_PORT` | `3306` | |
| `DB_DATABASE` | `server_panel` | Nama database aktual (bisa berbeda kalau di-custom saat install) |
| `DB_USERNAME` | `panel_app` | Akses penuh **hanya** ke `DB_DATABASE` |
| `DB_PASSWORD` | (random, unik per install) | |
| `DB_PROVISIONER_USERNAME` | `panel_provisioner` | Privilese `CREATE`/`DROP`/`CREATE USER`/`GRANT` — dipakai menu Database |
| `DB_PROVISIONER_PASSWORD` | (random, unik per install) | |
| `SESSION_LIFETIME` | `1800` (detik) | Absolute timeout session |
| `SESSION_IDLE_TIMEOUT` | `900` (detik) | Idle timeout session |
| `SESSION_SECURE_COOKIE` | `1` jika domain panel punya sertifikat SSL, `0` jika belum | Cookie `Secure` flag - browser MENOLAK menyimpan cookie `Secure` lewat HTTP polos, jadi kalau ini `1` sementara panel belum punya HTTPS aktif, login akan selalu terlihat "sukses" di server tapi terus terlempar balik ke `/login` (session tidak pernah benar-benar tersimpan di browser). `module_panel_sync_ssl_env()` (`modules/panel.sh`) menjaga nilai ini tetap sesuai kenyataan secara otomatis setiap kali `install.sh`/`update.sh`/`yp repair panel` jalan - jangan diubah manual kecuali tahu persis apa yang sedang dilakukan. |
| `PANEL_EXEC_SCRIPT` | `/opt/server-panel/scripts/panel-exec.sh` | Path yang dipanggil `Executor` |
| `NODEAPPS_HOME` | `/home/nodeapps` | |
| `NGINX_SITES_AVAILABLE` / `NGINX_SITES_ENABLED` | `/etc/nginx/sites-available` / `sites-enabled` | |
| `ACME_WEBROOT` | `/var/www/_letsencrypt` | Webroot Certbot |
| `LOG_PATH` | `/opt/server-panel/storage/logs` | |
| `BACKUP_PATH` | `/opt/server-panel/storage/backups` | |

Untuk cara membaca file ini di server: lihat
[Pemulihan Akun Admin](Pemulihan-Akun-Admin.md).

## State Installer

`modules/lib.sh` menyimpan progres instalasi supaya re-run bisa melewati
langkah yang sudah selesai:

- **File state**: `/var/lib/yuuka-installer/install.state` — daftar
  `state_mark`/`state_has` (mis. `cloudflare:service_installed`,
  `panel:deployed`, `system:firewall`).
- **Backup config sebelum overwrite**: `/var/backups/yuuka-installer/`
  (dibuat otomatis oleh `backup_path()` sebelum file konfigurasi yang
  sudah ada ditimpa — misalnya saat unit file systemd atau vhost Nginx
  ditulis ulang).
- **Log instalasi lengkap**: `/var/log/yuuka-installer/` (path persis
  ditampilkan di awal output `install.sh`; juga dipakai `deploymentLog()`
  di menu Log panel).

## File Kunci Lain di Server

| Path | Keterangan |
|---|---|
| `/etc/cloudflared/tunnel.env` | Token Cloudflare Tunnel, format `TUNNEL_TOKEN=...`, permission 600 — lihat [Cloudflare Tunnel](Cloudflare-Tunnel.md) |
| `/etc/systemd/system/cloudflared.service` | Unit systemd cloudflared |
| `/etc/sudoers.d/panel-exec` | Aturan `NOPASSWD` yang membatasi user `panel` hanya boleh menjalankan `panel-exec.sh` |
| `/opt/server-panel/storage/logs/panel-exec-audit.log` | Audit log setiap pemanggilan `panel-exec.sh` |
| `/opt/server-panel/storage/logs/app-error.log` | Log error aplikasi panel sendiri |
| `/opt/server-panel/storage/backups/` | Semua hasil backup (database/website/nodejs) |
| `sql/schema.sql` | Skema database, lihat [Skema Database](Skema-Database.md) |
