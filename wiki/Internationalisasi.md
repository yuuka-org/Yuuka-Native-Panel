# Internasionalisasi (i18n)

[← Kembali ke Home](Home.md)

## Cara kerja

Murni PHP array, tanpa gettext/.po dan tanpa dependency Composer apa pun
(konsisten dengan filosofi zero-dependency proyek ini - lihat
[Arsitektur](Arsitektur.md)):

- `app/lang/id.php` — bahasa Indonesia, **bahasa asli/utama panel ini**.
  Setiap string di file ini adalah string yang memang sudah ada di UI
  sebelum i18n dibuat.
- `app/lang/en.php` — terjemahan bahasa Inggris DARI `id.php`, bukan
  sebaliknya. Struktur key-nya harus identik dengan `id.php`.
- `app/helpers/locale.php` — class `PanelLocale` + fungsi global `t('group.key')`.

```php
<?= e(t('sidebar.dashboard')) ?>
<?= e(t('login.error_required')) ?>
```

Kalau key hilang di bahasa aktif, otomatis fallback ke `en.php`; kalau
hilang di situ juga, tampil apa adanya sebagai string key mentah
(`"sidebar.dashboard"`) - supaya UI tetap jalan (bukan fatal error) tapi
kelihatan jelas ada terjemahan yang kurang.

## Resolusi bahasa aktif

Urutan: **override per-sesi** (tombol bahasa di topbar/halaman login,
`PanelLocale::setSessionLocale()`, disimpan di `$_SESSION['locale']`) →
**default panel** (`Settings > Umum`, `SettingsService` key
`default_locale`) → `id` (fallback terakhir).

Override per-sesi sengaja TIDAK disimpan per-user di database - kalau
beberapa admin sering pakai panel yang sama dari akun berbeda tapi mau
bahasa yang sama tanpa mengatur ulang tiap login, cukup ubah default
panel di Settings. Kalau nanti benar-benar dibutuhkan preferensi
per-user yang persisten, tinggal tambah kolom `locale` ke `panel_users`
dan baca itu duluan sebelum session override di `PanelLocale::current()`.

## Status cakupan (per commit ini)

**Sudah pakai `t()`**: topbar (tombol pengaturan/logout/tema/sidebar,
pemilih bahasa), sidebar (semua label menu), footer, halaman login,
Settings > Umum (field Bahasa Default Panel).

**BELUM** — ini bukan celah yang lupa, tapi memang sengaja di-scope
seperti ini (fondasi dulu, cakupan penuh menyusul, sesuai permintaan
awal): mayoritas isi halaman (Website, Node.js Apps, Database, File
Manager, dst) masih hardcoded bahasa Indonesia. Menerjemahkan SEMUA
halaman itu kerja yang sangat besar (~90 file `public/*.php`) - dilakukan
bertahap, bukan sekaligus.

## Menambah string baru

1. Tambahkan key ke **kedua** `app/lang/id.php` dan `app/lang/en.php`,
   struktur/urutan key harus sama persis di kedua file.
2. Pakai `t('group.key')` di halaman - biasanya dibungkus `e()` juga
   kalau outputnya masuk ke atribut HTML/teks biasa: `<?= e(t('...')) ?>`.
3. Untuk string dengan bagian dinamis, pakai placeholder `{nama}`:
   ```php
   // lang file: 'greeting' => 'Halo, {name}!'
   t('common.greeting', ['name' => $username])
   ```

## Menambah bahasa baru (Crowdin dkk, menyusul)

Tambahkan `app/lang/<kode>.php` baru dengan struktur key identik dengan
`id.php`, lalu daftarkan kode-nya di `PanelLocale::AVAILABLE`
(`app/helpers/locale.php`) - otomatis muncul di pemilih bahasa topbar,
halaman login, dan dropdown Settings > Umum tanpa perubahan lain.
