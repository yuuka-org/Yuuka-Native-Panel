<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('cloudflare.manage');
    $action = $_POST['action'] ?? '';

    try {
        match ($action) {
            'restart' => CloudflareService::restart($user['id']),
            'stop' => CloudflareService::stop($user['id']),
            'start' => CloudflareService::start($user['id']),
            default => null,
        };
        flash('success', 'Perintah cloudflared dijalankan.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/cloudflare');
}

$status = CloudflareService::status();
$deploymentMode = Config::get('APP_DEPLOYMENT_MODE', 'direct');

$pageTitle = 'Cloudflare Tunnel';
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Cloudflare Tunnel</h4>
  <p class="text-muted mb-0">Network ingress opsional. Autentikasi panel tetap berjalan terpisah &amp; tetap wajib.</p>
</div>

<div class="alert alert-info">
  <strong>Mode deployment saat ini:</strong> <?= e($deploymentMode) ?>.
  Token tunnel tidak pernah ditampilkan di sini demi keamanan - token tersimpan di <code>/etc/cloudflared/tunnel.env</code> dengan permission 600.
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Status Tunnel</div>
      <div class="card-body">
        <?php if (!$status['configured']): ?>
          <div class="text-center py-4">
            <i class="bi bi-cloud-slash fs-1 text-muted"></i>
            <p class="text-muted mt-2 mb-0">Cloudflare Tunnel belum dikonfigurasi di server ini.</p>
            <p class="text-muted small">Panel tetap berfungsi normal melalui Nginx + IP publik.</p>
          </div>
        <?php else: ?>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span>Connection Status</span>
            <span><span class="status-dot <?= e($status['status']) ?>"></span><?= e($status['status']) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span>cloudflared Version</span>
            <span class="text-muted small"><?= e($status['version'] ?? 'unknown') ?></span>
          </div>
          <?php if (Rbac::can($user['role'], 'cloudflare.manage')): ?>
          <div class="d-flex gap-2 mt-3">
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="start">
              <button class="btn btn-sm btn-outline-success">Start</button>
            </form>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="restart">
              <button class="btn btn-sm btn-outline-warning">Restart</button>
            </form>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="stop">
              <button class="btn btn-sm btn-outline-secondary">Stop</button>
            </form>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Arsitektur</div>
      <div class="card-body small text-muted">
        <pre class="mb-0">Internet
  -> Cloudflare Network
  -> Cloudflare Tunnel (cloudflared)
  -> Nginx (localhost)
  -> PHP-FPM / Node.js (PM2)</pre>
        <p class="mt-3 mb-0">Opsional: aktifkan <strong>Cloudflare Access</strong> di dashboard Cloudflare Zero Trust untuk lapisan otentikasi tambahan di depan Cloudflare Tunnel. Ini bukan pengganti login panel.</p>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
