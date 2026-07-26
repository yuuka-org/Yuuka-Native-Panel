<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$embed = ($_GET['embed'] ?? '') === '1';
$embedSuffix = $embed ? '&embed=1' : '';
$app = NodeService::find($id);
if ($app === null) {
    flash('error', 'Aplikasi tidak ditemukan');
    redirect('/nodejs');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('nodejs.control');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'configure') {
            HealthCheckService::configure(
                $id,
                trim((string) $_POST['url']),
                (string) $_POST['method'],
                (int) $_POST['timeout'],
                (int) $_POST['interval']
            );
            flash('success', 'Health check dikonfigurasi.');
        } elseif ($action === 'disable') {
            HealthCheckService::disable($id);
            flash('success', 'Health check dinonaktifkan.');
        } elseif ($action === 'run_now') {
            $check = HealthCheckService::forApp($id);
            if ($check) {
                HealthCheckService::runCheck($check);
                flash('success', 'Health check dijalankan.');
            }
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs_health?id=' . $id . $embedSuffix);
}

$check = HealthCheckService::forApp($id);
$activeNodejsTab = 'health';

$pageTitle = 'Health Check - ' . $app['app_name'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/nodejs_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Health Check: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Informasional - status proses tetap berasal dari PM2, bukan health check ini.</p>
  </div>
  <a href="/nodejs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Informasional - status proses tetap berasal dari PM2, bukan health check ini.</p>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Konfigurasi</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="configure">
          <div class="mb-3">
            <label class="form-label">URL</label>
            <input type="url" name="url" class="form-control" value="<?= e($check['url'] ?? '') ?>" placeholder="http://127.0.0.1:<?= (int) $app['port'] ?>/health" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label">Method</label>
              <select name="method" class="form-select">
                <?php foreach (['GET', 'HEAD', 'POST'] as $m): ?>
                  <option value="<?= $m ?>" <?= ($check['http_method'] ?? 'GET') === $m ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <label class="form-label">Timeout (s)</label>
              <input type="number" name="timeout" class="form-control" value="<?= e((string) ($check['timeout_seconds'] ?? 5)) ?>" min="1" max="30">
            </div>
            <div class="col-4">
              <label class="form-label">Interval (s)</label>
              <input type="number" name="interval" class="form-control" value="<?= e((string) ($check['interval_seconds'] ?? 60)) ?>" min="10" max="3600">
            </div>
          </div>
          <button class="btn btn-primary">Simpan</button>
        </form>
        <?php if ($check): ?>
        <form method="post" class="mt-2">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="disable">
          <button class="btn btn-outline-secondary">Nonaktifkan</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Status Terakhir</span>
        <?php if ($check): ?>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="run_now">
          <button class="btn btn-sm btn-outline-primary">Run Now</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$check): ?>
          <p class="text-muted text-center py-4 mb-0">Health check belum dikonfigurasi.</p>
        <?php else: ?>
          <?php
            $statusClass = match ($check['last_status']) {
                'healthy' => 'success', 'unhealthy', 'connection_refused' => 'danger', 'timeout' => 'warning', default => 'secondary',
            };
          ?>
          <div class="d-flex justify-content-between mb-2"><span>Status</span><span class="badge text-bg-<?= $statusClass ?>"><?= e($check['last_status']) ?></span></div>
          <div class="d-flex justify-content-between mb-2"><span>HTTP Code</span><span><?= e((string) ($check['last_status_code'] ?? '-')) ?></span></div>
          <div class="d-flex justify-content-between mb-2"><span>Response Time</span><span><?= e((string) ($check['last_response_ms'] ?? '-')) ?> ms</span></div>
          <div class="d-flex justify-content-between mb-2"><span>Failure Count</span><span><?= e((string) $check['failure_count']) ?></span></div>
          <div class="d-flex justify-content-between mb-0"><span>Terakhir Dicek</span><span class="small text-muted"><?= e($check['last_checked_at'] ?? 'belum pernah') ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
