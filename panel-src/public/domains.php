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
        } elseif ($action === 'cloudflare_proxy') {
            DomainService::setCloudflareProxied((int) $_POST['id'], $_POST['proxied'] === '1', $user['id']);
            flash('success', 'Pengaturan Cloudflare proxy diperbarui.');
        } elseif ($action === 'issue_ssl') {
            Rbac::require('ssl.manage');
            SSLService::issueForDomain((string) $_POST['domain'], $user['email'], $user['id']);
            flash('success', 'Sertifikat SSL berhasil diterbitkan.');
        } elseif ($action === 'remove_ssl') {
            Rbac::require('ssl.manage');
            SSLService::removeCertificate((string) $_POST['domain'], $user['id']);
            flash('success', 'Sertifikat SSL dihapus.');
        } elseif ($action === 'upload_ssl') {
            Rbac::require('ssl.manage');
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
    redirect('/domains');
}

$domains = DomainService::listAll();

$pageTitle = 'Domain Management';
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Domain Management</h4>
  <p class="text-muted mb-0">Domain dibuat otomatis saat menambah Website PHP atau Aplikasi Node.js. Kelola SSL &amp; status di sini.</p>
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
        <tr>
          <td><?= e($d['domain']) ?></td>
          <td><span class="badge text-bg-light border"><?= $d['type'] === 'php' ? 'PHP Native' : 'Node.js' ?></span></td>
          <td><?= $d['ssl_enabled'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Tidak aktif</span>' ?></td>
          <td>
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="cloudflare_proxy">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <input type="hidden" name="proxied" value="<?= $d['cloudflare_proxied'] ? '0' : '1' ?>">
              <button class="btn btn-sm <?= $d['cloudflare_proxied'] ? 'btn-outline-warning' : 'btn-outline-secondary' ?>">
                <?= $d['cloudflare_proxied'] ? 'Proxied' : 'DNS Only' ?>
              </button>
            </form>
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
              <?php if ($d['ssl_enabled']): ?>
                <form method="post" class="d-inline" data-confirm="Hapus sertifikat SSL untuk <?= e($d['domain']) ?>?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="remove_ssl">
                  <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-shield-x"></i></button>
                </form>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="issue_ssl">
                  <input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                  <button class="btn btn-sm btn-outline-success"><i class="bi bi-shield-lock"></i> Issue SSL</button>
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

<?php include __DIR__ . '/partials/footer.php'; ?>
