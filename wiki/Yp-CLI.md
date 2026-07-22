# CLI `yp`

[← Kembali ke Home](Home.md)

CLI administrasi server, dijalankan sebagai root lewat SSH — pelengkap panel web untuk operasi yang butuh akses langsung ke server (reset kredensial saat tidak bisa login, restart service, dsb) tanpa perlu SQL manual seperti yang selama ini dilakukan lewat [Pemulihan Akun Admin](Pemulihan-Akun-Admin.md).

## Instalasi

Terpasang otomatis ke `/usr/local/bin/yp` setiap kali `sudo bash install.sh` dijalankan (baik instalasi pertama maupun re-run), lewat `module_panel_setup_installer_copy()` di `modules/panel.sh`. Tidak ada langkah instalasi terpisah.

## Arsitektur: `/opt/yuuka-installer`

`yp` **tidak** bergantung pada folder tempat kamu menjalankan `install.sh` pertama kali (mis. `~/Yuuka-Native-Panel`) — folder itu boleh dipindah/dihapus kapan saja tanpa merusak `yp`. Sebagai gantinya, setiap `install.sh` jalan, ia memastikan `/opt/yuuka-installer` adalah **clone git independen** dari repo yang sama:

- Kalau `/opt/yuuka-installer/.git` sudah ada → `git pull` untuk sinkronisasi.
- Kalau belum ada → clone dari remote `origin` yang terdeteksi di folder kerja saat itu; kalau tidak terdeteksi git sama sekali (instalasi dari zip/tarball), disalin langsung tanpa histori git (`yp update` nanti akan bilang tidak bisa auto-update).

`yp` sendiri men-source `modules/lib.sh`, `modules/nginx.sh`, `modules/panel.sh` dari `/opt/yuuka-installer` — persis fungsi yang sama dipakai `install.sh`, bukan implementasi terpisah.

## Pemakaian

```
yp <aksi> <target> [args...]
```

| Command | Fungsi |
|---|---|
| `yp start\|stop\|restart\|status panel` | Kontrol vhost Nginx panel (enable/disable symlink) + pool PHP-FPM panel |
| `yp start\|stop\|restart\|status service <nama>` | Wrapper `systemctl`, dibatasi whitelist: `nginx`, `mariadb`, `cloudflared`, `php{7.4-8.4}-fpm` (sama seperti whitelist di [panel-exec.sh](Panel-Exec-Reference.md)) |
| `yp reset-password` | Tampilkan daftar `panel_users`, pilih username, set password baru (generate otomatis atau input manual) — hash bcrypt otomatis |
| `yp reset-username` | Sama, untuk ganti username (validasi keunikan) |
| `yp change-port panel <port>` | Ubah `listen 80`/`listen [::]:80` di vhost panel ke port custom + `ufw allow` |
| `yp repair panel [--reset-env]` | Re-apply permission `/opt/server-panel`, pastikan `www-data` di grup `panel`, regenerate pool PHP-FPM & vhost Nginx dari kode saat ini, restart service terkait |
| `yp update` | `git pull` di `/opt/yuuka-installer` + redeploy `panel-src` ke `/opt/server-panel` (tanpa mengulang wizard) |
| `yp version` | Commit installer aktif + versi Nginx/MariaDB/PHP-FPM/cloudflared |
| `yp custom-build <modul>` | Rerun `module_<modul>_run_all` untuk satu komponen saja: `nginx`, `php`, `nodejs`, `mariadb`, `phpmyadmin`, `ssl`, `cloudflare`, `panel` |

## `yp repair panel --reset-env`

Kalau `.env` panel hilang/corrupt, opsi ini **regenerasi penuh** — tapi beda dengan sekadar menulis ulang `.env` baru secara naif (yang akan mengulang [bug password mismatch](Troubleshooting.md) yang pernah terjadi), langkah ini:

1. Generate password baru untuk `panel_app` & `panel_provisioner`.
2. **`ALTER USER`** langsung ke MariaDB dengan password baru itu.
3. Baru menulis `.env` baru dengan password yang **sama persis** dengan yang baru saja di-set di MariaDB.

Sehingga `.env` dan MariaDB dijamin selalu sinkron — tidak seperti kalau dilakukan manual satu-satu.

> **Peringatan**: regenerasi `.env` selalu membuat `APP_KEY` baru. Environment variable Node.js yang sudah terenkripsi dengan `APP_KEY` lama (lihat [Fitur Panel § Environment Variables](Fitur-Panel.md#environment-variables-nodejs_envphp)) tidak akan bisa didekripsi lagi — perlu di-input ulang manual lewat menu Environment tiap aplikasi setelah `--reset-env`.

## `yp custom-build` — Cakupan per Modul

Meniru filosofi `./build <komponen>` di DirectAdmin: membangun ulang satu komponen stack tanpa mengulang wizard penuh `install.sh`. Beberapa modul punya keterbatasan cakupan (dibanding kalau dijalankan lewat `install.sh` yang mengorkestrasi banyak langkah di `main()`):

| Modul | Yang dilakukan | Keterbatasan |
|---|---|---|
| `nginx` | Install + snippet + hardening | — |
| `php` | Install versi PHP + pilih default (interaktif) | — |
| `nodejs` | NVM + Node + PM2 | — |
| `mariadb` | Install + hardening + akun (aman di-re-run, lihat guard `state_has` di `module_mariadb_create_accounts`) | — |
| `phpmyadmin` | Install/reinstall binary + config PHP-FPM | **Tidak** regenerasi vhost Nginx phpMyAdmin — kalau belum pernah dikonfigurasi, lengkapi lewat `install.sh` tahap 8 |
| `ssl` | Pastikan Certbot terinstall + auto-renew aktif | **Tidak** menerbitkan sertifikat baru — pakai menu Domain di panel untuk itu |
| `cloudflare` | Prompt setup tunnel (interaktif) | — |
| `panel` | Redeploy `panel-src` saja | **Tidak** regenerasi pool PHP-FPM/vhost — pakai `yp repair panel` untuk itu |

## Keamanan

`yp` dijalankan sebagai root langsung via SSH — operator yang menjalankannya sudah punya akses root penuh, jadi whitelist service (`RE_SERVICE`) di sini adalah **pencegah salah ketik**, bukan boundary keamanan seperti whitelist di [panel-exec.sh](Panel-Exec-Reference.md) (yang memang menjadi satu-satunya batas privilese antara user `panel` tanpa privilese dan root). Jangan bingungkan keduanya — `yp` dan `panel-exec.sh` melayani model ancaman yang berbeda.
