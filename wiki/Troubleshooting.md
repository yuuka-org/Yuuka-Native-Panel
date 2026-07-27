# Troubleshooting

[← Kembali ke Home](Home.md)

Kumpulan masalah nyata yang pernah ditemui di proyek ini beserta akar
masalah dan cara diagnosanya. Ditambahkan berdasarkan insiden aktual, bukan
teori — kalau menemukan kasus baru, tambahkan pola yang sama di sini.

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

---

Menemukan bug atau kejanggalan lain yang belum ada di sini? Lihat
[cara melapor lewat GitHub Issues](../README.md#8-menemukan-bug-atau-kejanggalan)
di README — sertakan info yang diminta di situ supaya lebih cepat
ditelusuri.
