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
        if ($action === 'add') {
            NodeService::addDomain($id, trim((string) ($_POST['domain'] ?? '')), $user['id']);
            flash('success', 'Domain ditambahkan.');
        } elseif ($action === 'remove') {
            NodeService::removeDomain($id, (string) ($_POST['domain'] ?? ''), $user['id']);
            flash('success', 'Domain dihapus.');
        } elseif ($action === 'wildcard_enable') {
            NodeService::enableWildcard($id, $user['id']);
            flash('success', 'Wildcard hostname diaktifkan - aplikasi ini sekarang menerima domain apa pun yang diarahkan ke server ini.');
        } elseif ($action === 'wildcard_disable') {
            NodeService::disableWildcard($id, $user['id']);
            flash('success', 'Wildcard hostname dinonaktifkan.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs_domains?id=' . $id . $embedSuffix);
}

$domains = NodeService::listDomains($id);
$wildcardHolder = NginxService::wildcardHolder();
$activeNodejsTab = 'domains';

$pageTitle = 'Domain - ' . $app['app_name'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/nodejs_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Domain: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Semua domain di bawah proxy ke port internal yang sama: <code><?= (int) $app['port'] ?></code>.</p>
  </div>
  <a href="/nodejs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Semua domain proxy ke port internal yang sama: <code><?= (int) $app['port'] ?></code>.</p>
<?php endif; ?>

<div class="card stat-card mb-4">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Domain</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php if (empty($domains)): ?>
          <tr><td colspan="2" class="text-center text-muted py-4">Belum ada domain terpasang</td></tr>
        <?php endif; ?>
        <?php foreach ($domains as $d): ?>
        <tr>
          <td>
            <a href="http://<?= e($d['domain']) ?>" target="_blank"><?= e($d['domain']) ?></a>
            <?php if ($d['domain'] === $app['domain']): ?><span class="badge text-bg-light border ms-1">Primary</span><?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
            <form method="post" class="d-inline" data-confirm="Hapus domain <?= e($d['domain']) ?>? Situs Nginx-nya akan ikut dihapus.">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
              <button class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">Tambah Domain</div>
  <div class="card-body">
    <form method="post" class="d-flex gap-2 flex-wrap">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="add">
      <input type="text" name="domain" class="form-control" style="max-width:320px" placeholder="app2.contoh.com" required>
      <button class="btn btn-primary text-nowrap">Tambah Domain</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card stat-card mt-4">
  <div class="card-header bg-white fw-semibold">Wildcard Hostname (Cloudflare for SaaS / Custom Hostname)</div>
  <div class="card-body">
    <p class="text-muted small">
      Untuk layanan SaaS yang customer-nya pakai domain sendiri lewat Cloudflare Custom Hostname: aplikasi ini akan menerima request untuk <strong>domain apa pun</strong>
      yang diarahkan ke server ini (bukan cuma domain yang terdaftar di atas), termasuk akses langsung ke IP server. Hanya satu situs (website atau aplikasi Node.js) yang boleh
      mengaktifkan ini dalam satu server - aplikasi/website lain tetap jalan normal berdasarkan domain terdaftarnya masing-masing.
    </p>
    <?php if ((bool) $app['wildcard_enabled']): ?>
      <p class="mb-2"><span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Aktif</span> untuk aplikasi ini.</p>
      <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
      <form method="post" data-confirm="Nonaktifkan wildcard hostname untuk <?= e($app['app_name']) ?>?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="wildcard_disable">
        <button class="btn btn-outline-secondary">Nonaktifkan</button>
      </form>
      <?php endif; ?>
    <?php elseif ($wildcardHolder !== null): ?>
      <p class="mb-0 text-muted">Slot wildcard sedang dipakai oleh <strong><?= e($wildcardHolder['name']) ?></strong> (<?= $wildcardHolder['type'] === 'website' ? 'Website PHP' : 'Aplikasi Node.js' ?>) - nonaktifkan di sana dulu untuk memindahkannya ke sini.</p>
    <?php elseif (Rbac::can($user['role'], 'nodejs.control')): ?>
      <form method="post" data-confirm="Aktifkan wildcard hostname untuk <?= e($app['app_name']) ?>? Aplikasi ini akan menerima domain apa pun yang belum terdaftar di situs lain.">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="wildcard_enable">
        <button class="btn btn-outline-primary">Aktifkan Wildcard Hostname</button>
      </form>
    <?php endif; ?>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
