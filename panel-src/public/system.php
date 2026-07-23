<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('monitoring.view');

$user = Auth::user();
$canManage = Rbac::can($user['role'], 'server.manage_configuration');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'restart_service') {
        // Special-cased (not the usual flash()+redirect()) because
        // restarting a service can be the panel's own PHP-FPM pool - the
        // response must be fully flushed to the client via
        // fastcgi_finish_request() BEFORE the restart call is made, or the
        // pool could die mid-response. redirect() itself calls exit(),
        // which would happen before the restart call ever runs, so this
        // branch sends the redirect manually instead.
        Rbac::require('server.manage_configuration');
        try {
            $svc = (string) ($_POST['service'] ?? '');
            if (!Validator::serviceName($svc)) {
                throw new InvalidArgumentException('Service tidak dikenal');
            }
            flash('success', "Restart {$svc} dijadwalkan, tunggu beberapa detik lalu muat ulang halaman.");
            ActivityLog::record($user['id'], 'system.service_restart', "Restart service: {$svc}");
            header('Location: /system.php');
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            sys_service_restart($svc);
        } catch (InvalidArgumentException|RuntimeException $e) {
            flash('error', $e->getMessage());
            if (!headers_sent()) {
                header('Location: /system.php');
            }
        }
        exit;
    }

    try {
        if ($action === 'check_update') {
            $result = sys_installer_check_update();
            if (!$result['ok']) {
                flash('error', 'Gagal memeriksa update (cek koneksi/kredensial git di server).');
            } elseif ($result['behind'] > 0) {
                flash('success', "Ada update baru: {$result['behind']} commit di belakang.");
            } else {
                flash('success', 'Sudah menggunakan versi terbaru.');
            }
        } elseif ($action === 'self_update') {
            Rbac::require('server.manage_configuration');
            sys_installer_self_update();
            ActivityLog::record($user['id'], 'system.self_update', 'Update panel dimulai');
            flash('success', 'Update dimulai di background - pantau log di bawah.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/system.php');
}

$versionInfo = sys_installer_version_info();
$services = SystemService::serviceStatuses();
$updateRunning = sys_installer_self_update_status() === 'active';
$panelPhpFpmService = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm';

$pageTitle = 'Sistem';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Sistem</h4>
    <p class="text-muted mb-0">Versi panel, update, dan kontrol service</p>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Versi</div>
      <div class="card-body">
        <table class="table table-sm mb-3">
          <tbody>
            <tr><th class="text-muted fw-normal" style="width:40%">Commit installer</th><td><code><?= e($versionInfo['commit'] ?: '-') ?></code> <span class="text-muted small"><?= e($versionInfo['commit_date']) ?></span></td></tr>
            <tr><th class="text-muted fw-normal">Nginx</th><td><?= e($versionInfo['nginx'] ?: '-') ?></td></tr>
            <tr><th class="text-muted fw-normal">MariaDB</th><td><?= e($versionInfo['mariadb'] ?: '-') ?></td></tr>
            <tr><th class="text-muted fw-normal">Cloudflared</th><td><?= e($versionInfo['cloudflared'] ?: '-') ?></td></tr>
          </tbody>
        </table>
        <form method="post" class="d-inline">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="check_update">
          <button class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Cek Update</button>
        </form>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#selfUpdateModal" <?= $updateRunning ? 'disabled' : '' ?>>
          <i class="bi bi-cloud-download me-1"></i><?= $updateRunning ? 'Sedang update...' : 'Jalankan Update' ?>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Log Update</div>
      <div class="card-body">
        <pre class="log-viewer mb-0" id="updateLogBlock" data-refresh-url="/ajax_update_log.php" data-refresh-interval="4000" style="min-height:180px"><?= e(LogService::selfUpdateLog(300)) ?></pre>
        <div class="small text-muted mt-2" id="updateStatusLine"><?= $updateRunning ? 'Sedang berjalan...' : '' ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">Status &amp; Restart Layanan</div>
  <div class="card-body">
    <div class="row g-2">
      <?php foreach ($services as $name => $status): ?>
        <div class="col-md-6">
          <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2">
            <span><span class="status-dot <?= e($status) ?>"></span><?= e($name) ?><?= $name === $panelPhpFpmService ? ' <span class="badge text-bg-info ms-1">pool panel</span>' : '' ?></span>
            <?php if ($canManage): ?>
            <form method="post" data-confirm="<?= e($name === $panelPhpFpmService
                ? 'Ini pool PHP-FPM yang sedang melayani panel ini sendiri. Restart tetap aman (dijadwalkan beberapa detik), tapi halaman mungkin perlu dimuat ulang manual. Lanjutkan?'
                : "Restart {$name} sekarang?") ?>">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="restart_service">
              <input type="hidden" name="service" value="<?= e($name) ?>">
              <button class="btn btn-sm btn-outline-secondary">Restart</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="selfUpdateModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Jalankan Update Panel</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="self_update">
          <div class="alert alert-warning small mb-0">
            Ini akan menjalankan <code>update.sh</code> di server (git pull + redeploy + migrasi database + restart pool PHP-FPM panel). Prosesnya berjalan di background - jangan tutup tab ini, pantau progresnya lewat log di bawah. Panel sempat tidak bisa diakses sebentar saat pool-nya di-restart.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Ya, Jalankan Update</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('updateLogBlock').addEventListener('panel:refresh', function (e) {
  var d = e.detail;
  if (!d || !d.ok) return;
  var block = document.getElementById('updateLogBlock');
  block.textContent = d.log;
  block.scrollTop = block.scrollHeight;
  document.getElementById('updateStatusLine').textContent = d.running ? 'Sedang berjalan...' : '';
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
