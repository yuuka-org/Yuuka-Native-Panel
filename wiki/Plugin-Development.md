# Pengembangan Plugin

> **Baca ini dulu sebelum menulis plugin apa pun**: plugin yang aktif
> berjalan dengan **privilege ROOT PENUH** di server, tanpa sandbox di
> level kode. Ini pilihan arsitektur yang disengaja (bukan default yang
> direkomendasikan) - lihat [Arsitektur](Arsitektur.md) untuk konteks
> privilege bridge panel secara umum. Source plugin **tidak pernah**
> disimpan di repo `Yuuka-Native-Panel` ini - lihat `.gitignore` di root
> repo.

## Struktur Direktori

Plugin yang sudah diinstall hidup di `/opt/server-panel-plugins/<slug>/`
(di server, **di luar** `/opt/server-panel` - lihat komentar `PLUGIN_DIR`
di `panel-src/scripts/panel-exec.sh` untuk alasannya: supaya deploy ulang
panel lewat `rsync` tidak pernah menyentuh direktori ini sama sekali).

```
<slug>/
  plugin.json       (wajib - lihat format di bawah)
  pages/            (halaman PHP, di-route lewat manifest "routes")
  bin/               (script root-exec - HANYA bisa dijalankan lewat
                      PluginService::runScript(), tidak bisa diakses
                      langsung oleh proses PHP-FPM biasa)
  cron/              (script yang dijalankan cron sebagai root langsung,
                      lihat "cron" di manifest)
```

Saat instalasi, panel otomatis:
- `plugin.json` dan `pages/*` → `chmod 644`/`755` (bisa dibaca langsung
  oleh PHP-FPM, user `panel`, sama persis seperti file panel inti).
- `bin/*.sh` dan `cron/*` → `chmod 700`, `chown root:root` (**hanya**
  proses root yang bisa baca/eksekusi - PHP-FPM sendiri tidak bisa
  membacanya langsung, harus lewat `sudo panel-exec.sh plugin-exec`).

## Format `plugin.json`

```json
{
  "slug": "contoh-plugin",
  "name": "Nama Plugin",
  "version": "1.0.0",
  "description": "Deskripsi singkat.",
  "menu": [
    { "label": "Contoh Plugin", "icon": "bi-puzzle", "route": "index" }
  ],
  "routes": {
    "index": "pages/index.php",
    "settings": "pages/settings.php"
  },
  "cron": [
    { "schedule": "*/5 * * * *", "script": "sync.sh" }
  ],
  "exec_ops": ["reload", "block-ip"]
}
```

- **`slug`** — dibaca langsung dari file ini saat instalasi (bukan
  parameter terpisah), jadi harus konsisten dan cocok pola
  `^[a-z0-9][a-z0-9_-]{0,63}$`. Ini juga yang menentukan nama folder
  akhir plugin.
- **`menu`** — entri sidebar. Semua entri plugin apa pun tampil di bawah
  permission `plugin.manage` (admin-only) - manifest **tidak bisa**
  mendefinisikan permission sendiri yang lebih longgar.
- **`routes`** — peta `route name` → path relatif file PHP di dalam
  folder plugin (tidak boleh `..` atau diawali `/`). Diakses lewat
  `/plugin.php?slug=<slug>&route=<route>`, **wajib sesi login admin**
  (`Rbac::require('plugin.manage')` dicek di dispatcher sebelum file
  plugin di-`require`).
- **`api_routes`** — sama persis strukturnya dengan `routes`, tapi
  diakses lewat dispatcher **terpisah** `/plugin_api.php?slug=<slug>&route=<route>`
  yang **TIDAK** mengecek sesi login sama sekali - dipakai untuk caller
  dari luar yang tidak punya sesi panel (modul provisioning WHMCS,
  webhook, dst). Karena tidak ada sesi untuk dicek, **plugin sendiri yang
  wajib mengautentikasi pemanggilnya** (misal cek header shared-secret)
  di baris pertama file `api_routes`-nya sebelum melakukan apa pun -
  panel cuma menjamin path containment (file yang dijalankan benar-benar
  ada di dalam folder plugin itu), bukan siapa yang boleh memanggilnya.
  Dua manifest key ini sengaja dipisah (bukan satu peta yang sama) supaya
  penulis plugin tidak bisa salah taruh halaman admin-only di dispatcher
  publik atau sebaliknya.
- **`cron`** — tiap entri jadi satu baris di `/etc/cron.d/plugin-<slug>`,
  dijalankan **sebagai root langsung** (bukan lewat `plugin-exec`,
  karena file cron.d sudah dikontrol root dari awal). `script` relatif
  terhadap folder `cron/` plugin.
- **`exec_ops`** — whitelist nama script (tanpa `.sh`) di folder `bin/`
  yang boleh dipanggil lewat `PluginService::runScript()`. Script yang
  ADA di `bin/` tapi TIDAK terdaftar di sini tidak bisa dipanggil dari
  halaman plugin manapun - lapisan whitelist tambahan di atas pengecekan
  path oleh `panel-exec.sh` sendiri.

## Menulis halaman plugin

File di `pages/` di-`require` langsung oleh `public/plugin.php` - semua
helper/service panel inti (yang sudah dimuat `bootstrap.php`: `e()`,
`Csrf`, `flash()`, `Rbac`, `Executor`, dst) otomatis tersedia, persis
seperti halaman panel biasa. Untuk memakai layout panel (sidebar/topbar),
panggil header/footer lewat `APP_PATH` (konstanta global dari
`bootstrap.php`, path absolut ke root panel, `/opt/server-panel` di
server):

```php
<?php
declare(strict_types=1);
// $currentPlugin (array) sudah di-set oleh plugin.php - berisi slug + manifest.

$pageTitle = 'Contoh Plugin';
require APP_PATH . '/public/partials/header.php';
?>
<h4>Halo dari plugin!</h4>
<?php require APP_PATH . '/public/partials/footer.php'; ?>
```

## Menjalankan operasi root dari halaman plugin

```php
$result = PluginService::runScript('contoh-plugin', 'reload', ['arg1']);
if (!$result['ok']) {
    flash('error', 'Gagal: ' . $result['output']);
}
```

**Penting soal stdout/stderr**: `Executor::run()` (dipakai semua operasi
privileged di panel ini, bukan cuma plugin) HANYA mengembalikan **stdout**
di `output` kalau script keluar dengan exit code 0 - stderr saat itu
dibuang sepenuhnya. Kalau exit code bukan 0, `output` isinya stderr
(fallback ke stdout kalau stderr kosong). Jadi: tulis hasil/data yang
mau dibaca PHP ke **stdout**, pesan error ke **stderr**, dan selalu exit
non-zero (`exit 1`) kalau script gagal - itu yang jadi sinyal `ok: false`
di sisi PHP.

`runScript()` menolak nama script yang tidak ada di `exec_ops` manifest,
lalu panel-exec.sh (`op_plugin_exec`) memvalidasi ulang secara independen
(nama plugin & script harus cocok pola aman, path harus benar-benar ada
di dalam folder `bin/` plugin itu) sebelum menjalankan
`bin/<script>.sh` sebagai **root**, dengan argumen diteruskan apa adanya
(bukan digabung jadi satu string shell) dan STDIN diteruskan langsung -
pola yang sama persis dipakai di seluruh privilege bridge panel ini
untuk operasi inti.

## Install & test plugin

- **Dari panel** (Plugin > Install dari ZIP / Install dari Git) - plugin
  ter-install tapi **nonaktif** secara default, harus diaktifkan manual
  setelah kamu yakin sumbernya aman.
- **Lokal untuk development**: taruh source plugin di `plugins/<slug>/`
  atau `panel-src/plugins/<slug>/` di checkout kamu (sudah di-gitignore),
  lalu upload sebagai ZIP/push ke repo terpisah untuk diinstall via panel
  seperti biasa - panel tidak punya mode "load plugin langsung dari
  checkout lokal".
