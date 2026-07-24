<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_general') {
            Rbac::require('settings.manage');
            $pma = trim((string) ($_POST['phpmyadmin_url'] ?? ''));
            if ($pma !== '' && !filter_var($pma, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('URL phpMyAdmin tidak valid');
            }
            SettingsService::set('phpmyadmin_url', $pma);
            flash('success', 'Pengaturan disimpan.');
        } elseif ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');

            $stmt = Database::app()->prepare('SELECT password_hash FROM panel_users WHERE id = :id');
            $stmt->execute(['id' => $user['id']]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                throw new InvalidArgumentException('Password saat ini salah');
            }
            UserService::changePassword($user['id'], $new, $user['id']);
            flash('success', 'Password berhasil diubah.');
        } elseif ($action === 'update_session') {
            Rbac::require('settings.manage');
            $idle = (int) ($_POST['session_idle_timeout'] ?? 0);
            $lifetime = (int) ($_POST['session_lifetime'] ?? 0);
            if ($idle < 60 || $idle > 86400) {
                throw new InvalidArgumentException('Idle timeout harus 60-86400 detik');
            }
            if ($lifetime < 300 || $lifetime > 604800) {
                throw new InvalidArgumentException('Session lifetime harus 300-604800 detik');
            }
            SettingsService::set('session_idle_timeout', (string) $idle);
            SettingsService::set('session_lifetime', (string) $lifetime);
            flash('success', 'Pengaturan sesi disimpan.');
        } elseif ($action === 'update_basicauth') {
            Rbac::require('settings.manage');
            $enable = ($_POST['basicauth_action'] ?? '') === 'enable';
            if ($enable) {
                $username = trim((string) ($_POST['basicauth_username'] ?? ''));
                $password = (string) ($_POST['basicauth_password'] ?? '');
                if (!Validator::username($username)) {
                    throw new InvalidArgumentException('Username BasicAuth tidak valid (huruf/angka/._- , 3-64 karakter)');
                }
                if (strlen($password) < 8) {
                    throw new InvalidArgumentException('Password BasicAuth minimal 8 karakter');
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $result = Executor::run('panel-basicauth-set', ['enable', $username, $hash], null, 30);
                if (!$result['ok']) {
                    throw new RuntimeException('Gagal mengaktifkan BasicAuth: ' . $result['output']);
                }
                SettingsService::set('basicauth_enabled', '1');
                SettingsService::set('basicauth_username', $username);
                ActivityLog::record($user['id'], 'settings.basicauth_enable', "BasicAuth diaktifkan untuk user: {$username}");
                flash('success', 'BasicAuth diaktifkan. Coba buka panel di tab/browser baru untuk memastikan sebelum menutup sesi ini.');
            } else {
                $result = Executor::run('panel-basicauth-set', ['disable'], null, 30);
                if (!$result['ok']) {
                    throw new RuntimeException('Gagal menonaktifkan BasicAuth: ' . $result['output']);
                }
                SettingsService::set('basicauth_enabled', '0');
                ActivityLog::record($user['id'], 'settings.basicauth_disable', 'BasicAuth dinonaktifkan');
                flash('success', 'BasicAuth dinonaktifkan.');
            }
        } elseif ($action === 'update_security_entrance') {
            Rbac::require('settings.manage');
            $path = trim((string) ($_POST['security_entrance_path'] ?? ''));
            if ($path === '') {
                $result = Executor::run('panel-security-entrance-set', ['disable'], null, 30);
                if (!$result['ok']) {
                    throw new RuntimeException('Gagal menonaktifkan Security Entrance: ' . $result['output']);
                }
                SettingsService::set('security_entrance_path', '');
                ActivityLog::record($user['id'], 'settings.security_entrance_disable', 'Security Entrance dinonaktifkan');
                flash('success', 'Security Entrance dinonaktifkan - login kembali lewat /login.php.');
            } else {
                if (!Validator::securityEntrancePath($path)) {
                    throw new InvalidArgumentException('Path tidak valid (huruf/angka/-/_, 3-64 karakter)');
                }
                $result = Executor::run('panel-security-entrance-set', ['enable', $path], null, 30);
                if (!$result['ok']) {
                    throw new RuntimeException('Gagal mengaktifkan Security Entrance: ' . $result['output']);
                }
                SettingsService::set('security_entrance_path', $path);
                ActivityLog::record($user['id'], 'settings.security_entrance_enable', 'Security Entrance diaktifkan');
                flash('success', "Security Entrance aktif - CATAT path ini: /{$path}. Kalau lupa, jalankan 'yp security-entrance' via SSH.");
            }
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings.php');
}

$phpmyadminUrl = SettingsService::get('phpmyadmin_url');
$deploymentMode = Config::get('APP_DEPLOYMENT_MODE', 'direct');
$sessionIdleTimeout = (int) SettingsService::get('session_idle_timeout') ?: Config::getInt('SESSION_IDLE_TIMEOUT', 900);
$sessionLifetime = (int) SettingsService::get('session_lifetime') ?: Config::getInt('SESSION_LIFETIME', 1800);
$basicauthEnabled = SettingsService::get('basicauth_enabled') === '1';
$basicauthUsername = SettingsService::get('basicauth_username');
$securityEntrancePath = SettingsService::get('security_entrance_path');
$activeSettingsTab = 'general';

$pageTitle = 'Pengaturan';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/settings_nav.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Pengaturan</h4>
  <p class="text-muted mb-0">Konfigurasi panel &amp; akun Anda</p>
</div>

<div class="row g-3">
  <?php if (Rbac::can($user['role'], 'settings.manage')): ?>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Pengaturan Umum</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_general">
          <div class="mb-3">
            <label class="form-label">Deployment Mode</label>
            <input type="text" class="form-control" value="<?= e($deploymentMode) ?>" disabled>
            <div class="form-text">Ubah via installer / .env untuk menghindari perubahan konfigurasi Nginx yang tidak sengaja.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">URL phpMyAdmin</label>
            <input type="url" name="phpmyadmin_url" class="form-control" value="<?= e($phpmyadminUrl) ?>" placeholder="https://pma.domainanda.com">
          </div>
          <button class="btn btn-primary">Simpan</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Ubah Password Saya</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="mb-3">
            <label class="form-label">Password Saat Ini</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Password Baru</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required>
          </div>
          <button class="btn btn-primary">Ubah Password</button>
        </form>
      </div>
    </div>
  </div>

  <?php if (Rbac::can($user['role'], 'settings.manage')): ?>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Session Timeout</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_session">
          <div class="mb-3">
            <label class="form-label">Idle Timeout (detik)</label>
            <input type="number" name="session_idle_timeout" class="form-control" value="<?= e((string) $sessionIdleTimeout) ?>" min="60" max="86400" required>
            <div class="form-text">Otomatis logout kalau tidak ada aktivitas selama ini. Default 900 (15 menit).</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Session Lifetime (detik)</label>
            <input type="number" name="session_lifetime" class="form-control" value="<?= e((string) $sessionLifetime) ?>" min="300" max="604800" required>
            <div class="form-text">Batas total sesi sejak login, walau tetap aktif. Default 1800 (30 menit).</div>
          </div>
          <button class="btn btn-primary">Simpan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">BasicAuth (Lapisan Login Tambahan)</div>
      <div class="card-body">
        <p class="text-muted small">
          Menambah prompt login level-Nginx SEBELUM halaman login panel muncul - lapisan
          proteksi tambahan, bukan pengganti login panel.
          Status: <?php if ($basicauthEnabled): ?><span class="badge text-bg-success">Aktif (<?= e($basicauthUsername) ?>)</span><?php else: ?><span class="badge text-bg-secondary">Nonaktif</span><?php endif; ?>
        </p>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_basicauth">
          <div class="mb-3">
            <label class="form-label">Aksi</label>
            <select name="basicauth_action" class="form-select" required>
              <option value="enable">Aktifkan / Ubah</option>
              <option value="disable">Nonaktifkan</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="basicauth_username" class="form-control" value="<?= e($basicauthUsername) ?>" placeholder="admin">
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="basicauth_password" class="form-control" minlength="8" placeholder="Wajib diisi kalau Aktifkan/Ubah">
          </div>
          <button class="btn btn-primary">Simpan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Security Entrance</div>
      <div class="card-body">
        <div class="alert alert-warning small">
          <strong>Hati-hati:</strong> ini mengganti alamat halaman login panel dari
          <code>/login.php</code> jadi path rahasia - salah/lupa path berarti TIDAK BISA login
          sama sekali lewat browser. Kalau itu terjadi, jalankan
          <code>yp security-entrance off</code> lewat SSH untuk mengembalikan seperti semula.
        </div>
        <p class="text-muted small">
          Status: <?php if ($securityEntrancePath !== ''): ?><span class="badge text-bg-success">Aktif: /<?= e($securityEntrancePath) ?></span><?php else: ?><span class="badge text-bg-secondary">Nonaktif (/login.php)</span><?php endif; ?>
        </p>
        <form method="post" data-confirm="Yakin? Kalau path ini salah/lupa, satu-satunya cara masuk lagi adalah lewat SSH ('yp security-entrance off').">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_security_entrance">
          <div class="mb-3">
            <label class="form-label">Path Rahasia (kosongkan untuk menonaktifkan)</label>
            <div class="input-group">
              <input type="text" name="security_entrance_path" id="securityEntrancePathInput" class="form-control" value="<?= e($securityEntrancePath) ?>" placeholder="mis. x7k2p9-masuk" maxlength="64">
              <button type="button" class="btn btn-outline-secondary" onclick="generateSecurityEntrancePath()">Acak</button>
            </div>
            <div class="form-text">Huruf/angka/-/_, 3-64 karakter.</div>
          </div>
          <button class="btn btn-primary">Simpan</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function generateSecurityEntrancePath() {
  var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  var out = '';
  for (var i = 0; i < 16; i++) {
    out += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  document.getElementById('securityEntrancePathInput').value = out;
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
