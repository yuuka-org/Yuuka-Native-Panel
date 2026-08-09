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

$domains = NodeService::listDomains($id);
$domainNames = array_column($domains, 'domain');

if (empty($domainNames)) {
    $activeNodejsTab = 'response_log';
    $pageTitle = 'Response Log - ' . $app['app_name'];
    include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
    ?>
    <div class="d-flex">
    <?php include __DIR__ . '/partials/nodejs_settings_nav.php'; ?>
    <div class="flex-grow-1">
    <?php if (!$embed): ?><?php include __DIR__ . '/partials/flash.php'; ?><?php endif; ?>
    <div class="alert alert-info">Aplikasi ini belum punya domain terpasang - Access/Error Log Nginx baru tersedia setelah domain ditambahkan (tab Domain).</div>
    </div>
    </div>
    <?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
    <?php
    exit;
}

$domain = (string) ($_GET['domain'] ?? $domainNames[0]);
if (!in_array($domain, $domainNames, true)) {
    $domain = $domainNames[0];
}
$type = ($_GET['type'] ?? 'access') === 'error' ? 'error' : 'access';
$lines = max(20, min(2000, (int) ($_GET['lines'] ?? 200)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('backup.manage');
    $postDomain = (string) ($_POST['domain'] ?? '');
    try {
        if (!in_array($postDomain, $domainNames, true)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (($_POST['type'] ?? '') === 'error') {
            LogService::clearNginxError($postDomain);
        } else {
            LogService::clearNginxAccess($postDomain);
        }
        flash('success', 'Log dibersihkan.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs_response_log?id=' . $id . '&domain=' . urlencode($postDomain) . '&type=' . (($_POST['type'] ?? '') === 'error' ? 'error' : 'access') . $embedSuffix);
}

$content = $type === 'error' ? LogService::nginxError($domain, $lines) : LogService::nginxAccess($domain, $lines);
$activeNodejsTab = 'response_log';

$pageTitle = 'Response Log - ' . $app['app_name'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/nodejs_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Response Log: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Access Log &amp; Error Log Nginx per domain (reverse proxy ke aplikasi). Log output aplikasi Node.js sendiri ada di tab Logs.</p>
  </div>
  <a href="/nodejs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Access Log &amp; Error Log Nginx per domain. Log output aplikasi Node.js sendiri ada di tab Logs.</p>
<?php endif; ?>

<div class="card stat-card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
      <div class="col-md-4">
        <label class="form-label small mb-1">Domain</label>
        <select name="domain" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($domainNames as $dn): ?>
            <option value="<?= e($dn) ?>" <?= $domain === $dn ? 'selected' : '' ?>><?= e($dn) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Tipe</label>
        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="access" <?= $type === 'access' ? 'selected' : '' ?>>Access Log</option>
          <option value="error" <?= $type === 'error' ? 'selected' : '' ?>>Error Log</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Baris</label>
        <select name="lines" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ([100, 200, 500, 1000, 2000] as $l): ?>
            <option value="<?= $l ?>" <?= $lines === $l ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
      </div>
    </form>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span><?= $type === 'error' ? 'Error Log' : 'Access Log' ?> - <code><?= e($domain) ?></code></span>
    <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
    <form method="post" data-confirm="Bersihkan <?= $type === 'error' ? 'error' : 'access' ?> log untuk <?= e($domain) ?>?">
      <?= Csrf::field() ?>
      <input type="hidden" name="domain" value="<?= e($domain) ?>">
      <input type="hidden" name="type" value="<?= e($type) ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Bersihkan Log</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <pre class="log-viewer m-0 p-3" style="max-height:70vh; overflow:auto; font-size:0.8rem; background:#0d1117; color:#c9d1d9; border-radius:0 0 .5rem .5rem;"><?= e($content !== '' ? $content : '(log kosong)') ?></pre>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
