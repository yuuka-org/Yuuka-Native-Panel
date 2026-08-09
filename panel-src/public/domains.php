<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('domain.manage');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'toggle') {
            DomainService::toggle((int) $_POST['id'], $_POST['enable'] === '1', $user['id']);
            flash('success', 'Status domain diperbarui.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/domains');
}

$domains = DomainService::listAll();
// Cloudflare Proxy is no longer a manual admin toggle - it's directly
// observable from DNS (does this domain currently resolve into
// Cloudflare's published edge ranges?), so it's detected fresh on every
// page load and persisted via syncCloudflareProxied(). null means the
// domain didn't resolve at all just now (down/not propagated yet) -
// shown as "Tidak diketahui" rather than guessing, and the last known
// stored value is left untouched in that case.
foreach ($domains as &$d) {
    $detected = DomainService::syncCloudflareProxied((int) $d['id'], $d['domain']);
    $d['cloudflare_proxied_live'] = $detected;
}
unset($d);

$pageTitle = 'Domain Management';
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Domain Management</h4>
  <p class="text-muted mb-0">Domain dibuat otomatis saat menambah Website PHP atau Aplikasi Node.js. Status Cloudflare Proxy dideteksi otomatis dari DNS - sertifikat SSL diatur di tab SSL masing-masing situs.</p>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Domain</th><th>Tipe</th><th>SSL</th><th>Cloudflare Proxy</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($domains)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada domain terdaftar</td></tr>
      <?php endif; ?>
      <?php foreach ($domains as $d): ?>
        <?php $sslUrl = $d['type'] === 'php' ? '/website_ssl?id=' . (int) $d['website_id'] : '/nodejs_ssl?id=' . (int) $d['nodejs_app_id']; ?>
        <tr>
          <td><?= e($d['domain']) ?></td>
          <td><span class="badge text-bg-light border"><?= $d['type'] === 'php' ? 'PHP Native' : 'Node.js' ?></span></td>
          <td><?= $d['ssl_enabled'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Tidak aktif</span>' ?></td>
          <td>
            <?php if ($d['cloudflare_proxied_live'] === null): ?>
              <span class="badge text-bg-light border" title="Domain tidak resolve saat ini">Tidak diketahui</span>
            <?php elseif ($d['cloudflare_proxied_live']): ?>
              <span class="badge text-bg-warning" title="Terdeteksi otomatis dari DNS">Proxied</span>
            <?php else: ?>
              <span class="badge text-bg-secondary" title="Terdeteksi otomatis dari DNS">DNS Only</span>
            <?php endif; ?>
          </td>
          <td><?= $d['is_enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
          <td class="text-end">
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <input type="hidden" name="enable" value="<?= $d['is_enabled'] ? '0' : '1' ?>">
              <button class="btn btn-sm btn-outline-secondary"><i class="bi <?= $d['is_enabled'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
            </form>
            <?php if (Rbac::can($user['role'], 'ssl.manage')): ?>
              <a href="<?= e($sslUrl) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-shield-lock"></i> Lihat Sertifikat</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
