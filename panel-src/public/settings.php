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
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings.php');
}

$phpmyadminUrl = SettingsService::get('phpmyadmin_url');
$deploymentMode = Config::get('APP_DEPLOYMENT_MODE', 'direct');
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
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
