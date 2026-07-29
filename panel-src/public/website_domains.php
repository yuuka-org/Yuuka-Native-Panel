<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('website.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$embed = ($_GET['embed'] ?? '') === '1';
$embedSuffix = $embed ? '&embed=1' : '';
$site = NginxService::find($id);
if ($site === null) {
    flash('error', 'Website tidak ditemukan');
    redirect('/websites');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_domain') {
            Rbac::require('website.create');
            NginxService::addDomain($id, trim((string) ($_POST['domain'] ?? '')), $user['id']);
            flash('success', 'Domain ditambahkan.');
        } elseif ($action === 'remove_domain') {
            Rbac::require('website.create');
            NginxService::removeDomain($id, (string) ($_POST['domain'] ?? ''), $user['id']);
            flash('success', 'Domain dihapus.');
        } elseif ($action === 'issue_ssl') {
            Rbac::require('ssl.manage');
            SSLService::issueForDomain((string) $_POST['domain'], $user['email'], $user['id']);
            flash('success', 'Sertifikat SSL berhasil diterbitkan.');
        } elseif ($action === 'remove_ssl') {
            Rbac::require('ssl.manage');
            SSLService::removeCertificate((string) $_POST['domain'], $user['id']);
            flash('success', 'Sertifikat SSL dihapus.');
        } elseif ($action === 'wildcard_enable') {
            Rbac::require('website.create');
            NginxService::enableWildcard($id, $user['id']);
            flash('success', 'Wildcard hostname diaktifkan.');
        } elseif ($action === 'wildcard_disable') {
            Rbac::require('website.create');
            NginxService::disableWildcard($id, $user['id']);
            flash('success', 'Wildcard hostname dinonaktifkan.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_domains?id=' . $id . $embedSuffix);
}

$domains = NginxService::listDomains($id);
$activeWebsiteTab = 'domains';

$pageTitle = 'Domain - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Domain &amp; SSL: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Semua domain di bawah melayani Document Root &amp; versi PHP yang sama.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Semua domain di bawah melayani Document Root &amp; versi PHP yang sama.</p>
<?php endif; ?>

<div class="card stat-card mb-4">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Domain</th><th>SSL</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($domains as $d): ?>
        <tr>
          <td>
            <a href="http://<?= e($d['domain']) ?>" target="_blank"><?= e($d['domain']) ?></a>
            <?php if ($d['domain'] === $site['domain']): ?><span class="badge text-bg-light border ms-1">Primary</span><?php endif; ?>
          </td>
          <td><?= $d['ssl_enabled'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Tidak aktif</span>' ?></td>
          <td class="text-end text-nowrap">
            <?php if (Rbac::can($user['role'], 'ssl.manage')): ?>
              <?php if ($d['ssl_enabled']): ?>
                <form method="post" class="d-inline" data-confirm="Hapus sertifikat SSL untuk <?= e($d['domain']) ?>?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="remove_ssl">
                  <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Hapus SSL"><i class="bi bi-shield-x"></i></button>
                </form>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="issue_ssl">
                  <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                  <button class="btn btn-sm btn-outline-success" title="Terbitkan SSL"><i class="bi bi-shield-lock"></i></button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($d['domain'] !== $site['domain'] && Rbac::can($user['role'], 'website.create')): ?>
            <form method="post" class="d-inline" data-confirm="Hapus domain <?= e($d['domain']) ?>? Situs Nginx-nya akan ikut dihapus.">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="remove_domain">
              <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
              <button class="btn btn-sm btn-outline-danger" title="Hapus Domain"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (Rbac::can($user['role'], 'website.create')): ?>
<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold">Tambah Domain</div>
  <div class="card-body">
    <form method="post" class="d-flex gap-2 flex-wrap">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="add_domain">
      <input type="text" name="domain" class="form-control" style="max-width:320px" placeholder="alias.contoh.com" required pattern="^[a-zA-Z0-9.\-]+$">
      <button class="btn btn-primary text-nowrap">Tambah Domain</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">Wildcard Hostname (Cloudflare for SaaS / Custom Hostname)</div>
  <div class="card-body">
    <p class="text-muted small">
      Untuk layanan SaaS yang customer-nya pakai domain sendiri lewat Cloudflare Custom Hostname: website ini akan menerima request untuk <strong>domain apa pun</strong>
      yang diarahkan ke port wildcard-nya (termasuk akses langsung ke IP server di port itu). Lebih dari satu situs boleh punya slot wildcard sendiri-sendiri
      sekarang - masing-masing dapat port lokal berbeda otomatis, tapi tetap butuh Cloudflare Tunnel-nya <strong>sendiri</strong> per slot (kecuali slot pertama/port 80).
      Lihat halaman wiki "Fitur Panel" untuk cara setup Tunnel tambahan.
    </p>
    <?php if ((bool) $site['wildcard_enabled']): ?>
      <p class="mb-2"><span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Aktif</span> di port <code><?= (int) $site['wildcard_port'] ?></code> untuk website ini.</p>
      <?php if (Rbac::can($user['role'], 'website.create')): ?>
      <form method="post" data-confirm="Nonaktifkan wildcard hostname untuk <?= e($site['domain']) ?>?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="wildcard_disable">
        <button class="btn btn-outline-secondary">Nonaktifkan</button>
      </form>
      <?php endif; ?>
    <?php elseif (Rbac::can($user['role'], 'website.create')): ?>
      <form method="post" data-confirm="Aktifkan wildcard hostname untuk <?= e($site['domain']) ?>? Website ini akan dapat port wildcard sendiri (otomatis dipilih) - butuh Cloudflare Tunnel terpisah untuk yang bukan port 80.">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="wildcard_enable">
        <button class="btn btn-outline-primary">Aktifkan Wildcard Hostname</button>
      </form>
    <?php endif; ?>
    <?php $otherSlots = array_filter(NginxService::wildcardSlots(), static fn(array $s): bool => !($s['type'] === 'website' && $s['id'] === $id)); ?>
    <?php if (!empty($otherSlots)): ?>
      <hr>
      <p class="small text-muted mb-1">Slot wildcard lain yang sedang aktif di server ini:</p>
      <ul class="small text-muted mb-0">
        <?php foreach ($otherSlots as $s): ?>
          <li><?= e($s['name']) ?> (<?= $s['type'] === 'website' ? 'Website PHP' : 'Aplikasi Node.js' ?>) - port <code><?= (int) $s['port'] ?></code></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
