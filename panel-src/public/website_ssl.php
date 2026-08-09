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
    Rbac::require('ssl.manage');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'issue_ssl') {
            SSLService::issueForDomain((string) $_POST['domain'], $user['email'], $user['id'], (string) ($_POST['key_type'] ?? 'rsa'));
            flash('success', 'Sertifikat SSL berhasil diterbitkan.');
        } elseif ($action === 'remove_ssl') {
            SSLService::removeCertificate((string) $_POST['domain'], $user['id']);
            flash('success', 'Sertifikat SSL dihapus.');
        } elseif ($action === 'upload_ssl') {
            if (!isset($_FILES['cert']) || $_FILES['cert']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Berkas sertifikat (fullchain/cert) gagal diunggah atau tidak dipilih');
            }
            if (!isset($_FILES['key']) || $_FILES['key']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Berkas private key gagal diunggah atau tidak dipilih');
            }
            $certPem = (string) file_get_contents($_FILES['cert']['tmp_name']);
            $keyPem = (string) file_get_contents($_FILES['key']['tmp_name']);
            SSLService::uploadManualCertificate((string) $_POST['domain'], $certPem, $keyPem, $user['id']);
            flash('success', 'Sertifikat SSL manual berhasil diterapkan.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_ssl?id=' . $id . $embedSuffix);
}

$domains = NginxService::listDomains($id);
$activeWebsiteTab = 'ssl';

$pageTitle = 'SSL - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">SSL: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Sertifikat SSL untuk setiap domain website ini, diterbitkan lewat Let's Encrypt atau diunggah manual.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Sertifikat SSL untuk setiap domain website ini, diterbitkan lewat Let's Encrypt atau diunggah manual.</p>
<?php endif; ?>

<div class="alert alert-info small">
  <strong>RSA vs ECDSA:</strong> RSA lebih kompatibel secara luas (default, disarankan untuk domain yang lewat Cloudflare/CDN lain - trust store proxy kadang belum mengenali chain ECDSA terbaru Let's Encrypt sehingga bisa muncul SSL Handshake Failed di sisi proxy walau sertifikatnya sendiri valid). ECDSA lebih modern &amp; ringan, cocok kalau proxy/CDN yang dipakai sudah pasti mendukungnya.
</div>

<div class="card stat-card">
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
                <form method="post" class="d-inline-flex align-items-center gap-1">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="issue_ssl">
                  <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                  <select name="key_type" class="form-select form-select-sm" style="width:auto;" title="Tipe sertifikat">
                    <option value="rsa" selected>RSA (Kompatibel)</option>
                    <option value="ecdsa">ECDSA (Modern)</option>
                  </select>
                  <button class="btn btn-sm btn-outline-success" title="Terbitkan SSL (Let's Encrypt)"><i class="bi bi-shield-lock"></i></button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadSsl<?= (int) $d['id'] ?>" title="Upload SSL Manual"><i class="bi bi-upload"></i></button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (Rbac::can($user['role'], 'ssl.manage') && !$d['ssl_enabled']): ?>
        <div class="modal fade" id="uploadSsl<?= (int) $d['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post" enctype="multipart/form-data">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Upload SSL Manual - <?= e($d['domain']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="upload_ssl">
                  <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                  <p class="text-muted small">Untuk sertifikat yang sudah dibeli/diterbitkan di luar panel (bukan Let's Encrypt), atau domain yang tidak bisa dijangkau langsung oleh challenge HTTP-01 certbot.</p>
                  <div class="mb-3">
                    <label class="form-label">Sertifikat (fullchain/cert, .pem/.crt)</label>
                    <input type="file" name="cert" class="form-control" accept=".pem,.crt,.cer,.txt" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Private Key (.pem/.key, tanpa passphrase)</label>
                    <input type="file" name="key" class="form-control" accept=".pem,.key,.txt" required>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-primary">Terapkan</button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</div>
</div>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
