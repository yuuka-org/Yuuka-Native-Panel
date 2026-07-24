<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('database.view');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            Rbac::require('database.manage');
            DatabaseService::createDatabase(
                trim((string) ($_POST['db_name'] ?? '')),
                trim((string) ($_POST['db_user'] ?? '')),
                (string) ($_POST['db_password'] ?? ''),
                trim((string) ($_POST['note'] ?? '')) ?: null,
                $user['id']
            );
            flash('success', 'Database berhasil dibuat.');
        } elseif ($action === 'delete') {
            Rbac::require('database.manage');
            DatabaseService::dropDatabase((int) $_POST['id'], $user['id']);
            flash('success', 'Database dihapus.');
        } elseif ($action === 'backup') {
            Rbac::require('backup.manage');
            $dbName = (string) ($_POST['db_name'] ?? '');
            BackupService::backupDatabase($dbName, $user['id']);
            flash('success', "Backup {$dbName} dibuat. Lihat di Pengaturan > Backup & Restore.");
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/databases.php');
}

$registry = DatabaseService::listRegistry();
$live = DatabaseService::listLive();
$liveMap = [];
foreach ($live as $row) {
    $liveMap[$row['name']] = $row['size_mb'];
}
// Only fetched/decrypted for roles that can actually manage databases -
// a Viewer (read-only monitoring by design) must not be able to read or
// copy a raw credential that grants full read-write MariaDB access.
// Wrapped defensively: DbCredentialsStore throws if the sqlite3 PHP
// extension isn't installed (see its own guard) - a missing module on the
// server must degrade this page to "password tidak tersedia" per row
// (same fallback already shown for pre-existing rows with no stored
// credential), not take down the whole Database menu with a 500.
$credentialsMap = [];
if (Rbac::can($user['role'], 'database.manage')) {
    try {
        foreach ($registry as $db) {
            $credentialsMap[$db['db_name']] = DbCredentialsStore::get($db['db_name']);
        }
    } catch (RuntimeException $e) {
        $credentialsMap = [];
        flash('error', $e->getMessage());
    }
}

$pageTitle = 'Database Management';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Database Management</h4>
    <p class="text-muted mb-0">MariaDB - dapat digunakan aplikasi PHP maupun Node.js</p>
  </div>
  <?php if (Rbac::can($user['role'], 'database.manage')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDbModal"><i class="bi bi-plus-lg me-1"></i>Buat Database</button>
  <?php endif; ?>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Database</th><th>User</th><th>Password</th><th>Ukuran</th><th>Catatan</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if (empty($registry)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada database terdaftar</td></tr>
      <?php endif; ?>
      <?php foreach ($registry as $db): $cred = $credentialsMap[$db['db_name']] ?? null; ?>
        <tr>
          <td>
            <code><?= e($db['db_name']) ?></code>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-copy="<?= e($db['db_name']) ?>" title="Copy nama database"><i class="bi bi-clipboard"></i></button>
          </td>
          <td>
            <?= e($db['db_user']) ?>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-copy="<?= e($db['db_user']) ?>" title="Copy username"><i class="bi bi-clipboard"></i></button>
          </td>
          <td>
            <?php if ($cred !== null): ?>
              <span id="pw-<?= (int) $db['id'] ?>" class="font-monospace small" data-hidden="1" data-value="<?= e($cred['password']) ?>">••••••••</span>
              <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-toggle-secret="pw-<?= (int) $db['id'] ?>" title="Tampilkan/sembunyikan"><i class="bi bi-eye"></i></button>
              <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-copy="<?= e($cred['password']) ?>" title="Copy password"><i class="bi bi-clipboard"></i></button>
            <?php elseif (!Rbac::can($user['role'], 'database.manage')): ?>
              <span class="text-muted small">••••••••</span>
            <?php else: ?>
              <span class="text-muted small" title="Dibuat sebelum fitur ini ada - hapus &amp; buat ulang untuk menyimpan password">tidak tersedia</span>
            <?php endif; ?>
          </td>
          <td><?= isset($liveMap[$db['db_name']]) ? e((string) $liveMap[$db['db_name']]) . ' MB' : '-' ?></td>
          <td class="text-muted small"><?= e($db['note'] ?? '') ?></td>
          <td class="text-muted small"><?= e($db['created_at']) ?></td>
          <td class="text-end">
            <a href="/pma_redirect.php?db=<?= urlencode($db['db_name']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Buka di phpMyAdmin"><i class="bi bi-box-arrow-up-right"></i></a>
            <?php if (Rbac::can($user['role'], 'backup.manage')): ?>
            <form method="post" class="d-inline" data-confirm="Buat backup database <?= e($db['db_name']) ?> sekarang?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="backup">
              <input type="hidden" name="db_name" value="<?= e($db['db_name']) ?>">
              <button class="btn btn-sm btn-outline-secondary" title="Backup Sekarang"><i class="bi bi-cloud-arrow-down"></i></button>
            </form>
            <?php endif; ?>
            <?php if (Rbac::can($user['role'], 'database.manage')): ?>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delDb<?= (int) $db['id'] ?>"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
        <div class="modal fade" id="delDb<?= (int) $db['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Hapus Database</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $db['id'] ?>">
                  <div class="alert alert-warning">Tindakan ini akan menghapus database <strong><?= e($db['db_name']) ?></strong> dan seluruh isinya secara permanen. Pertimbangkan membuat backup terlebih dahulu lewat tombol Backup Sekarang di baris ini.</div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-danger">Ya, Hapus</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="createDbModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Buat Database Baru</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Nama Database</label>
            <input type="text" name="db_name" class="form-control" pattern="^[a-zA-Z0-9_]{1,64}$" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nama User</label>
            <input type="text" name="db_user" class="form-control" pattern="^[a-zA-Z0-9_]{1,32}$" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <div class="input-group">
              <input type="text" name="db_password" id="createDbPassword" class="form-control" minlength="8" required>
              <button type="button" class="btn btn-outline-secondary" data-generate-password="createDbPassword" title="Generate password acak"><i class="bi bi-magic"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Catatan (opsional)</label>
            <input type="text" name="note" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Buat</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
