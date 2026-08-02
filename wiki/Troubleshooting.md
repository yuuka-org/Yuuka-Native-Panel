# Troubleshooting

[← Kembali ke Home](Home.md)

Kumpulan masalah nyata yang pernah ditemui di proyek ini beserta akar
masalah dan cara diagnosanya. Ditambahkan berdasarkan insiden aktual, bukan
teori — kalau menemukan kasus baru, tambahkan pola yang sama di sini.

## Login sukses (tidak ada error) tapi selalu balik lagi ke halaman `/login`

**Gejala**: instalasi selesai tanpa error, akun admin berhasil dibuat,
halaman login tampil normal. Username/password yang benar dimasukkan,
tombol Login ditekan, tidak ada pesan error — tapi browser cuma balik lagi
ke `/login?redirect=%2Fdashboard`, seolah tidak pernah login sama sekali.
`storage/logs/php-fpm-error.log` dan `app-error.log` kosong/tidak ada
(bukan fatal error PHP — kalau itu penyebabnya, error itu SELALU
tercatat di sana).

**Akar masalah**: cookie session panel diset `Secure` (`bootstrap.php`,
dikontrol oleh `.env`'s `SESSION_SECURE_COOKIE`) supaya tidak bisa dicuri
lewat koneksi HTTP biasa. Browser MANA PUN menolak menyimpan cookie
`Secure` kalau halamannya sendiri diakses lewat HTTP polos (bukan HTTPS) —
jadi server-side login benar-benar berhasil (session dibuat, redirect ke
`/dashboard` dikirim), tapi cookie pembuktinya tidak pernah tersimpan di
browser, sehingga request berikutnya ke `/dashboard` dianggap belum login
dan dilempar balik ke `/login`. Paling sering kejadian pada instalasi baru
di mode *direct* saat DNS domain panel belum diarahkan ke server pada
waktu instalasi — penerbitan SSL otomatis gagal (`Certbot ... DNS problem:
NXDOMAIN`), panel jatuh ke mode HTTP-only, tapi `.env` dulu tetap
memaksa `SESSION_SECURE_COOKIE=1`.

**Fix**: `module_panel_sync_ssl_env()` (`modules/panel.sh`) sekarang
menyamakan `SESSION_SECURE_COOKIE`/`APP_URL` di `.env` dengan status SSL
yang SEBENARNYA (cek langsung keberadaan
`/etc/letsencrypt/live/<domain>/fullchain.pem`), dan `module_panel_nginx_vhost()`
di file yang sama sekarang benar-benar menambahkan blok `listen 443` +
redirect HTTP→HTTPS begitu sertifikat ada (sebelumnya vhost panel SELALU
HTTP-only apa pun status SSL-nya). Keduanya berjalan otomatis di setiap
`install.sh`/`update.sh`/`yp repair panel` — kalau baru mengalami ini di
instalasi lama, jalankan `sudo bash update.sh` untuk memperbaikinya tanpa
perlu login dulu (perbaikan ini murni di level installer/Nginx, tidak
memerlukan sesi panel yang valid).

Begitu DNS domain panel sudah diarahkan ke server, terbitkan SSL langsung
dari **Settings > SSL Panel** di dalam panel (tidak perlu SSH lagi) - lihat
`panel-ssl-issue` di `wiki/Panel-Exec-Reference.md`. Sebelum fitur ini ada,
"Domain Management" yang disebut pesan penutup installer TIDAK menangani
domain panel sendiri (menu itu cuma untuk domain website customer) -
kalau ketemu instalasi lama yang masih menunjuk ke sana, arahkan ke
Settings > SSL Panel sebagai gantinya.

## `Service cloudflared tidak ditemukan` langsung disusul `aktif dan terhubung`

**Gejala**: log installer menampilkan `[WARN] Service cloudflared tidak
ditemukan` lalu tepat setelahnya `[OK] cloudflared service aktif dan
terhubung` — dua pesan yang saling kontradiktif.

**Akar masalah**: `service_enable_now()` lama di `modules/lib.sh` memakai
`service_exists()` (`systemctl list-unit-files | grep ...`) sebagai gerbang
sebelum `enable`+`start`. Tepat setelah unit file baru ditulis +
`daemon-reload`, `list-unit-files` bisa false-negative — sehingga
`enable`/`start` di-skip diam-diam. Pesan "aktif dan terhubung" yang
muncul setelahnya berasal dari pengecekan `is-active` yang **terpisah**,
yang kebetulan true karena proses lama (dari instalasi sebelumnya) masih
berjalan — bukan bukti bahwa config baru sudah diterapkan.

**Fix**: `service_enable_now()` sekarang langsung mencoba `systemctl
enable` sebagai satu-satunya sumber kebenaran (bukan `service_exists`
dulu). Kalau `enable` gagal, baru dianggap "tidak ditemukan".

## cloudflared aktif tapi tunnel tidak connect di dashboard

**Gejala**: `systemctl status cloudflared` menunjukkan `active (running)`,
tapi Cloudflare Zero Trust Dashboard tetap menampilkan **"Waiting for your
Tunnel to connect..."** untuk tunnel yang baru dibuat.

**Cara diagnosa**:
```bash
sudo systemctl status cloudflared --no-pager   # lihat baris ExecStart di bagian CGroup
sudo journalctl -u cloudflared -n 80 --no-pager
```

**Akar masalah yang ditemukan**: `systemctl start` adalah **no-op** kalau
service sudah berstatus `active`. Jadi kalau `install.sh` dijalankan ulang
dan menulis ulang unit file (token/config baru) sambil cloudflared versi
LAMA masih berjalan (dari instalasi sebelumnya, atau tunnel lain yang
tidak terkait), `daemon-reload` **tidak** merestart proses yang sedang
berjalan — hanya memperbarui definisi unit di systemd. Proses lama tetap
hidup memakai token/tunnel yang lama. Tandanya persis:
```
systemd[1]: cloudflared.service: Current command vanished from the unit
file, execution of the command list won't be resumed.
```
Baris `ExecStart` di output `systemctl status` (bagian `CGroup:`) akan
menunjukkan command line **lama** (mis. `--token eyJ...` tertanam
langsung), bukan `EnvironmentFile=` + `tunnel run` polos yang seharusnya.

**Fix permanen**: `service_enable_now()` di `modules/lib.sh` sekarang
memakai `systemctl restart` (bukan `start`) kalau service sudah aktif,
supaya config baru selalu benar-benar diterapkan.

**Fix cepat manual** (tanpa perlu redeploy dulu):
```bash
sudo systemctl restart cloudflared
sudo systemctl status cloudflared --no-pager   # pastikan ExecStart sudah benar & tunnelID sesuai
```

Lihat juga [Cloudflare Tunnel](Cloudflare-Tunnel.md) untuk cara kerja
lengkap token-based tunnel di proyek ini.

## `mysql` menampilkan usage help, bukan hasil query

**Gejala**: menjalankan `mysql -u root <db> "UPDATE ...;"` malah
menampilkan teks bantuan `Usage: mysql [OPTIONS] [database]`, bukan hasil
query.

**Akar masalah**: lupa flag `-e`. Tanpa `-e`, string SQL dibaca sebagai
argumen **database kedua**, bukan sebagai query — `mysql` gagal parse
argumen dan menampilkan usage help, tanpa pernah terkoneksi ke database.

**Fix**: selalu sertakan `-e "..."`, atau untuk query yang mengandung
karakter `$` (lihat kasus berikut), gunakan heredoc.

## Query SQL yang mengandung hash bcrypt (`$2y$12$...`) rusak lewat shell

**Gejala**: `UPDATE panel_users SET password_hash='$2y$12$...' WHERE ...`
terlihat berhasil dijalankan (tidak ada error), tapi login tetap gagal
dengan password baru.

**Akar masalah**: hash bcrypt PHP selalu mengandung tanda `$` (format
`$2y$12$<22 karakter salt><31 karakter hash>`). Kalau query dibungkus
**double quotes** (`"..."`) di bash, `$2y`, `$12`, dan `$<salt>` di-expand
sebagai variabel shell (kebanyakan tidak terdefinisi → jadi string kosong)
**sebelum** perintah sampai ke `mysql` — hash yang tersimpan jadi rusak
walau tidak ada pesan error sama sekali.

**Fix**: jangan pernah bungkus hash bcrypt dengan double quotes di bash.
Gunakan heredoc dengan delimiter yang di-quote (mencegah ekspansi apa pun
di dalamnya):
```bash
mysql -u root <db> <<'SQL'
UPDATE panel_users SET password_hash='$2y$12$...' WHERE username='...';
SQL
```
Verifikasi hasilnya persis (58 karakter, tidak terpotong):
```bash
mysql -u root <db> -e "SELECT username, password_hash FROM panel_users WHERE username='<username>';"
```

Lihat [Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) untuk prosedur
lengkap reset password.

## Instalasi terhenti tidak terduga

`install.sh` men-trap error apa pun (`set -uo pipefail` + `trap ... ERR`)
dan menunjuk ke log lengkap: `/var/log/yuuka-installer/`. Selalu cek log
ini dulu — biasanya baris terakhir sebelum trap terpicu sudah cukup
menunjukkan modul dan perintah mana yang gagal.

## Perubahan kode tidak terlihat di server setelah edit lokal

Ingat: repo lokal (tempat kamu edit) dan server adalah dua checkout
terpisah. Perubahan baru berlaku di server setelah:
1. Kode benar-benar dipindahkan ke server (`git pull` setelah commit+push,
   atau `rsync`/`scp` manual).
2. Untuk file PHP (`panel-src/`): re-run `install.sh` (tahap 9 me-rsync
   ulang) atau rsync manual — langsung aktif tanpa restart apa pun.
3. Untuk modul shell (`modules/*.sh`): hanya berpengaruh kalau tahap
   instalasi terkait dijalankan ulang secara eksplisit. Lihat
   [Re-run untuk Update](Instalasi.md#re-run-untuk-update).

## phpMyAdmin auto-login (signon) gagal, redirect balik ke form login

**Gejala**: klik "Kelola Database" di panel mengarah ke phpMyAdmin, tapi
malah menampilkan form login phpMyAdmin biasa (bukan langsung masuk),
kadang tanpa pesan error yang jelas di browser.

**Akar masalah** (tiga lapis, semua harus benar sekaligus):
1. Pool PHP-FPM phpMyAdmin memakai `php_admin_value[session.save_path]`
   di config pool — nilai `php_admin_value` dikunci `PHP_INI_SYSTEM`,
   sehingga `session_save_path()` dari kode PHP **silently no-op**, sesi
   tetap ditulis ke lokasi default. Fix: pakai `php_value` (bukan
   `php_admin_value`) supaya runtime-overridable.
2. PHP session save handler `files` memvalidasi **UID pembuat** file sesi.
   Kalau proses yang menulis sesi (pool panel, user `panel`) berbeda UID
   dari proses yang membacanya (pool phpMyAdmin, dulunya `www-data`),
   phpMyAdmin akan menolak sesi dengan galat internal "Session data file
   is not created by your uid" — ini **bukan** masalah permission/chmod,
   jadi `chmod 777` pun tidak akan menolong. Fix permanen: samakan user
   OS kedua pool (`user`/`group` pool phpMyAdmin diubah ke `panel`).
3. Pool phpMyAdmin (user non-root) tidak boleh menulis ke `/var/log`
   default — `error_log()` gagal diam-diam tanpa pesan apa pun di layar,
   membuat diagnosa poin 1 & 2 di atas jauh lebih sulit. Fix: arahkan
   `error_log` pool ke path yang dimiliki `panel`
   (`storage/logs/phpmyadmin-fpm-error.log`).

**Cara diagnosa cepat**: cek `storage/logs/phpmyadmin-fpm-error.log` dulu
(bukan `/var/log/php*-fpm.log`) — kalau kosong padahal signon jelas gagal,
curiga dulu ke `open_basedir`/permission direktori log itu sendiri.

## Terminal: `bwrap: setting up uid map: Permission denied`

**Gejala**: membuka menu Terminal di panel menampilkan galat `bwrap:
setting up uid map: Permission denied` alih-alih shell.

**Akar masalah**: kernel modern (Ubuntu terbaru) membatasi
`unprivileged user namespaces` lewat sysctl
`kernel.apparmor_restrict_unprivileged_userns=1` secara default —
`bwrap` (dipakai untuk sandbox Terminal) butuh userns tanpa privilege
untuk bisa jalan.

**Fix yang SALAH** (jangan lakukan): mematikan
`apparmor_restrict_unprivileged_userns` secara global via sysctl —
ini melemahkan proteksi userns untuk **seluruh sistem**, bukan cuma
Terminal, dan berisiko dari sisi keamanan.

**Fix yang benar (dipakai proyek ini)**: profil AppArmor khusus per-binary
di `/etc/apparmor.d/yuuka-panelterm-bwrap`
(`flags=(unconfined) { userns, }`) yang hanya mengizinkan `bwrap` dipakai
Terminal untuk membuat userns, tanpa menyentuh proteksi global. Fix ini
idempotent dan otomatis diterapkan ulang tiap `install.sh`/`update.sh`
jalan, jadi aman kalau server di-restart atau di-reinstall.

## Login/panel mengembalikan `405 Not Allowed` setelah URL `.php` dihapus

**Gejala**: submit form (login atau form apa pun) tiba-tiba menampilkan
`405 Not Allowed` dari Nginx, padahal sebelumnya normal.

**Akar masalah**: konfigurasi `try_files $uri $uri.php $uri/
/index.php?$query_string;` di vhost panel membuat Nginx menemukan file
`.php` lewat `try_files` lalu menyajikannya lewat **jalur file statis**
(hanya mendukung GET/HEAD), bukan menyerahkannya ke blok `fastcgi_pass` —
akibatnya method POST ditolak dengan 405.

**Fix**: pisahkan resolusi clean-URL ke named location + `rewrite ...
last` (bukan `try_files` langsung ke `.php`), supaya request tetap lewat
blok regex `\.php$` yang menangani PHP-FPM:
```nginx
location / {
    if ($request_uri ~ "^(/[^?]*)\.php(\?.*)?$") { return 301 $1$2; }
    try_files $uri $uri/ @clean_url;
}
location @clean_url {
    if (-f $document_root$uri.php) { rewrite ^ $uri.php last; }
    rewrite ^ /index.php last;
}
```
Setelah perubahan clean-URL semacam ini, selalu tes eksplisit **POST**
(bukan cuma buka halaman via GET) sebelum menganggap beres — 405 di kasus
ini hanya muncul saat submit form.

## Nama user database dipakai ulang, kredensial database lain "berubah" sendiri

**Gejala**: password koneksi database yang sudah lama jalan tiba-tiba
tidak valid lagi, padahal tidak ada yang mengubahnya secara eksplisit —
biasanya setelah membuat database baru dengan nama user MySQL yang
kebetulan sama dengan yang sudah dipakai database lain.

**Akar masalah**: MySQL/MariaDB user (`CREATE USER ... IDENTIFIED BY
...`) itu unik per **nama user**, bukan per database. Membuat database
baru dengan `db_user` yang sudah ada akan menimpa password user itu di
level MySQL, sementara password yang tersimpan di tabel panel untuk
database lama tetap yang lama — kredensial lama jadi tidak sinkron
walau tidak pernah "diubah" lewat panel.

**Fix**: `DatabaseService::createDatabase()` sekarang menolak pembuatan
database baru kalau `db_user` yang diminta sudah dipakai database lain
(cek keunikan sebelum `CREATE USER`).

## Cloudflare Custom Hostname (wildcard) + Tunnel: selalu 404, tidak ada log sama sekali

**Gejala**: domain customer yang didaftarkan lewat Cloudflare for SaaS
Custom Hostname (fitur wildcard Website/Node.js App di panel) selalu
menampilkan `HTTP ERROR 404` polos di browser. Certificate status dan
Hostname status di dashboard Cloudflare sama-sama `Active`, TLS handshake
sukses (SNI cocok, sertifikat valid) kalau dicek lewat `curl -v`. Tapi
`sudo journalctl -u cloudflared -f` **maupun** log akses/error Nginx
(`/var/log/nginx/wildcard-*-access.log`) sama sekali tidak menunjukkan
request itu pernah masuk, walau sudah dicoba berkali-kali dari browser.

**Cara pastikan ini penyebabnya** (jangan asumsi, verifikasi dulu):
```bash
# Test lokal di server, lewati Cloudflare & Tunnel sepenuhnya - kalau ini
# BERHASIL (dapat respons dari app-nya), infra Nginx/app di server sudah
# benar, dan masalahnya pasti ada di jalur Cloudflare -> Tunnel.
curl -sv -H "Host: <domain-custom-hostname>" http://127.0.0.1/ 2>&1 | tail -30
```

**Akar masalah**: cloudflared mencocokkan Public Hostname rule
berdasarkan header `Host` request yang **benar-benar diterima**, bukan
berdasarkan lewat DNS record/Fallback Origin mana request itu datang.
Untuk trafik Custom Hostname SaaS, Cloudflare meneruskan request ke
Tunnel dengan `Host` = domain custom milik tenant (mis.
`pelanggan.domainmereka.com`), **bukan** `Host` = domain Fallback Origin
kamu sendiri (mis. `cf-origin.domainkamu.com`). Karena domain custom itu
tidak match dengan hostname manapun yang terdaftar eksplisit di rule
Tunnel, cloudflared selalu jatuh ke **Catch-All Rule** - yang defaultnya
`service: http_status:404`, dibalas instan tanpa pernah menyentuh
Nginx/app sama sekali (makanya tidak ada jejak di log manapun di server).

**Fix**: set Catch-All Rule Tunnel ke target yang sama dengan route
domain panel/app biasa (`http://127.0.0.1:80`). Dashboard Cloudflare versi
baru **tidak lagi** menampilkan opsi Catch-All Rule lewat UI "Add route"
(field hostname-nya wajib diisi, tidak bisa dikosongkan/wildcard) - harus
lewat API langsung:
```bash
# 1. Lihat config ingress yang sekarang ada (jangan asal timpa)
curl -s -X GET "https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/cfd_tunnel/{TUNNEL_ID}/configurations" \
  -H "X-Auth-Email: {EMAIL_AKUN}" \
  -H "X-Auth-Key: {GLOBAL_API_KEY_ATAU_TOKEN}" \
  -H "Content-Type: application/json"

# 2. PUT ulang array 'ingress' yang sama persis, TAPI baris terakhir
#    (yang tanpa field "hostname" - itu Catch-All-nya) diganti service-nya
#    ke http://127.0.0.1:80. Contoh (sesuaikan rule lain dengan hasil GET
#    di atas, jangan dihapus):
curl -s -X PUT "https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/cfd_tunnel/{TUNNEL_ID}/configurations" \
  -H "X-Auth-Email: {EMAIL_AKUN}" \
  -H "X-Auth-Key: {GLOBAL_API_KEY_ATAU_TOKEN}" \
  -H "Content-Type: application/json" \
  --data '{"config":{"ingress":[{"service":"http://127.0.0.1:80","hostname":"panel-atau-app-kamu.domain.com"},{"service":"http://127.0.0.1:80"}],"warp-routing":{"enabled":false}}}'
```
Respons yang berhasil menaikkan angka `version` di `result` - kalau
`version`-nya sama dengan sebelum PUT, berarti belum benar-benar
ke-apply, GET ulang untuk pastikan.

**Peringatan keamanan**: pakai **API Token** yang di-scope ke permission
`Account > Cloudflare Tunnel > Edit` saja untuk ini, bukan Global API Key
(akses penuh ke seluruh akun Cloudflare) - kalau terpaksa pakai Global Key
buat testing cepat, **langsung di-roll/regenerate** setelah selesai, dan
jangan pernah tempel isi token/key-nya ke tempat yang tercatat/ter-log
(termasuk chat AI apa pun) - anggap bocor begitu sudah pernah diketik di
luar terminal sendiri.

## Deploy Node.js App: `bash: line 1: pm2: command not found`

**Gejala**: menambahkan app Node.js baru lewat panel gagal dengan pesan
"Gagal menjalankan aplikasi via PM2: bash: line 1: pm2: command not
found" — padahal PM2 sudah terinstall dan app Node.js lain yang sudah
ada tetap bisa di-start/restart/stop normal tanpa masalah.

**Akar masalah**: `pm2` di-install sebagai paket npm global
(`npm install -g pm2`) di bawah **satu** versi Node.js tertentu yang
dikelola nvm (versi default nodeapps saat instalasi) — nvm menyimpan
package global terpisah per-versi, tidak dibagi lintas versi. Deploy
app baru yang memilih **versi Node.js berbeda** dari versi itu
menjalankan `nvm use <versi-app>` dulu sebelum `pm2 start` (supaya
proses app-nya benar-benar jalan di versi yang dipilih) — tapi ini juga
otomatis mengganti `PATH`, sehingga binary `pm2` dari versi default
ikut hilang dari `PATH` di saat yang sama, tepat sebelum `pm2 start`
dipanggil. Operasi lain (start/restart/reload/stop app yang **sudah**
berjalan) tidak kena karena PM2 daemon menyimpan sendiri interpreter
Node yang dipakai per-app dari saat pertama `pm2 start` — tidak perlu
`nvm use` lagi setelahnya, jadi cuma proses **deploy app baru** (atau
redeploy) yang kena.

**Fix**: `op_pm2_deploy()` di `panel-exec.sh` sekarang meresolusi path
absolut `pm2` (`command -v pm2`) **sebelum** `nvm use` mengganti `PATH`,
lalu memanggil `pm2` lewat path absolut itu setelah versi Node
di-switch — jadi `pm2` selalu ketemu terlepas dari versi Node yang
dipilih untuk app-nya. Update ke commit terbaru (`sudo bash update.sh`)
untuk dapat fix ini; server yang masih pakai kode lama akan terus kena
error ini setiap kali deploy app dengan versi Node selain versi
default.

---

Menemukan bug atau kejanggalan lain yang belum ada di sini? Lihat
[cara melapor lewat GitHub Issues](../README.md#8-menemukan-bug-atau-kejanggalan)
di README — sertakan info yang diminta di situ supaya lebih cepat
ditelusuri.
