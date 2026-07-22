# Instalasi

[← Kembali ke Home](Home.md)

## Cara Pakai

```bash
git clone <repo-ini> yuuka-panel && cd yuuka-panel
sudo bash install.sh
```

Ikuti prompt interaktif (domain panel, email admin, mode deployment, pilihan
SSL, pilihan Cloudflare Tunnel). Di akhir instalasi, URL panel + kredensial
admin pertama ditampilkan **satu kali** — catat segera (lihat
[Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) kalau lupa).

> **Aman dijalankan ulang.** Setiap modul memeriksa state sebelum bertindak
> (lihat `state_mark` / `state_has` di `modules/lib.sh`, disimpan di
> `/var/lib/yuuka-installer/install.state`): package yang sudah ada dilewati,
> `.env` & data existing tidak disentuh, dan backup otomatis dibuat sebelum
> menimpa file konfigurasi (`backup_path()`, disimpan ke
> `/var/backups/yuuka-installer/`). Ini juga cara resmi untuk **mendorong
> update kode terbaru** ke server yang sudah terinstall — lihat catatan
> penting di bagian [Re-run untuk Update](#re-run-untuk-update) di bawah.

## Urutan Tahap `install.sh`

`install.sh` menjalankan modul secara berurutan:

1. **Cek root & versi Ubuntu** (20.04 / 22.04 / 24.04 didukung penuh; versi
   lain diperingatkan tapi bisa dilanjutkan).
2. **Input awal**: domain panel, email admin, mode deployment
   (direct/tunnel/hybrid).
3. **Update sistem** + dependency dasar + user sistem `panel` & `nodeapps`.
4. **MariaDB**: install, hardening dasar (setara `mysql_secure_installation`),
   buat akun `panel_provisioner` dan `panel_app`, import skema
   (`sql/schema.sql`).
5. **Nginx**: install, snippet bersama, hardening `server_tokens off`.
6. **PHP 7.4 – 8.4**: tambah PPA `ondrej/php`, install tiap versi + extension
   (bcmath, curl, fpm, gd, intl, mbstring, mysql, opcache, readline, soap,
   xml, zip, + redis/imagick jika tersedia). Versi yang gagal install
   dilewati tanpa menggagalkan seluruh instalasi. User memilih versi
   default di akhir.
7. **Node.js**: NVM diinstall di bawah `$HOME` milik user `nodeapps`, lalu
   Node 18/20/22, lalu PM2 global, lalu `pm2 startup systemd -u nodeapps`
   + `pm2 save` supaya semua aplikasi otomatis kembali online setelah reboot.
8. **phpMyAdmin**: didownload manual (bukan `apt install phpmyadmin`),
   dikonfigurasi untuk PHP-FPM, user memilih akses via subdomain atau path
   `/phpmyadmin`.
9. **Panel**: deploy `panel-src/` ke `/opt/server-panel` (rsync, exclude
   `storage/` dan `.env`), pool PHP-FPM khusus (user `panel`, `open_basedir`
   dikunci), `.env` dengan kredensial unik, sudoers untuk `panel-exec.sh`,
   akun admin pertama, vhost Nginx, cron health-check, logrotate.
10. **SSL**: opsional, via Certbot mode webroot (dilewati kalau mode
    `tunnel` dipilih — Cloudflare menangani TLS di edge).
11. **Cloudflare Tunnel**: opsional (mode tunnel/hybrid), token diminta
    interaktif. Lihat [Cloudflare Tunnel](Cloudflare-Tunnel.md).
12. **Finalisasi Service**: restart `nginx`, `mariadb`,
    `php${PHP_DEFAULT_VERSION}-fpm`.
13. **Ringkasan akhir**: URL panel, username, password (ditampilkan sekali),
    daftar versi PHP/Node.js aktif, lokasi log instalasi.

## Update Tanpa Mengulang Wizard: `update.sh`

Untuk server yang **sudah terinstall dan sedang dipakai** (terutama prod),
`sudo bash install.sh` penuh biasanya bukan pilihan yang nyaman — dia akan
menanyakan ulang domain/email/mode deployment/SSL/Cloudflare Tunnel dari
awal. `update.sh` (di root repo, sejajar dengan `install.sh`) melakukan hal
yang sama seperti re-run installer untuk bagian **kode panel saja**, tanpa
wizard apa pun:

```bash
cd ~/Yuuka-Native-Panel   # clone repo yang kamu pakai
sudo bash update.sh
```

Yang dilakukan (reuse fungsi yang sama persis dengan `install.sh`, lihat
`modules/panel.sh`):
1. `git pull` di clone tempat `update.sh` dijalankan.
2. `module_panel_setup_installer_copy` — sinkronkan `/opt/yuuka-installer`
   (dipakai [CLI `yp`](Yp-CLI.md)) + install/refresh binary `yp`.
3. `module_panel_deploy_files` — rsync `panel-src/` ke `/opt/server-panel`,
   langsung aktif tanpa restart apa pun.
4. Kalau `yp` sudah tersedia: `yp repair panel` — regenerasi pool PHP-FPM
   & vhost Nginx dari kode terbaru (**ini yang me-restart PHP-FPM & reload
   Nginx** — lihat [CLI yp § repair](Yp-CLI.md), sub-detik downtime untuk
   semua website di versi PHP yang sama).

Aman dijalankan berkali-kali. Kalau `/opt/yuuka-installer` sempat rusak/
belum ada (mis. error `rsync: change_dir "/opt/yuuka-installer/panel-src"
failed`), `update.sh` otomatis memperbaikinya di langkah 2 — tidak perlu
bootstrap manual.

**Catatan**: langkah 3 hanya menyalin `panel-src/`. Kalau update itu juga
menyentuh `modules/*.sh` di luar yang sudah tercakup `yp repair panel`
(instalasi paket baru, perubahan MariaDB/Nginx/PHP/Node.js/Cloudflare),
tetap perlu `yp custom-build <modul>` yang relevan atau `install.sh` penuh.

## Re-run `install.sh` Penuh (Alternatif)

`sudo bash install.sh` tetap aman dijalankan ulang kapan saja karena setiap
modul idempotent — pilihan ini masuk akal kalau kamu memang perlu
menjalankan ulang tahap lain (bukan sekadar update kode panel) atau lebih
nyaman menjawab ulang wizard-nya. Yang perlu diperhatikan saat re-run:

- **File PHP panel** (apa pun di `panel-src/`) otomatis ter-*rsync* ulang ke
  `/opt/server-panel` di tahap 9 — langsung aktif, tidak perlu restart apa
  pun (PHP-FPM baca file fresh tiap request).
- **Modul shell** (`modules/*.sh`) hanya berpengaruh pada instalasi/
  konfigurasi *sistem* — perubahan di sana baru benar-benar diterapkan ke
  service yang sudah berjalan kalau tahap terkait dijalankan ulang secara
  eksplisit. Contoh: mengubah `modules/cloudflare.sh` tidak otomatis
  memperbarui tunnel yang sudah terpasang; kamu tetap perlu memilih ulang
  mode tunnel/hybrid di tahap 11 dan paste ulang token.
- `service_enable_now()` (di `modules/lib.sh`) me-*restart* service kalau
  sudah aktif (bukan cuma `start`, yang no-op pada service yang sudah
  jalan) — supaya config baru benar-benar diterapkan, bukan diam-diam
  diabaikan. Lihat [Troubleshooting](Troubleshooting.md#cloudflared-aktif-tapi-tunnel-tidak-connect-di-dashboard)
  untuk kasus nyata yang melatarbelakangi fix ini.
- Kalau hanya ingin mendorong perubahan `panel-src/` tanpa mengulang seluruh
  wizard (domain/email/mode/token), transfer manual filenya
  (`rsync`/`scp`) ke `/opt/server-panel` sudah cukup untuk file PHP murni.

## Kompatibilitas Ubuntu

- **Didukung penuh & diuji secara desain**: Ubuntu Server 20.04 LTS, 22.04
  LTS, 24.04 LTS.
- Versi lain akan memicu peringatan dan konfirmasi manual sebelum instalasi
  dilanjutkan (beberapa paket PHP dari PPA `ondrej/php` mungkin belum
  tersedia untuk codename yang sangat baru/lama — installer akan melewati
  versi yang gagal tanpa menghentikan seluruh proses).
- Arsitektur CPU: `amd64` dan `arm64` (cloudflared dan seluruh paket apt
  mendukung keduanya; installer mendeteksi arsitektur otomatis untuk
  download `cloudflared`).

## Log Instalasi

Log lengkap instalasi disimpan di `/var/log/yuuka-installer/` (path persis
ditampilkan di baris pertama output `install.sh`). Kalau instalasi terhenti
tidak terduga, cek log ini dulu sebelum lapor bug.
