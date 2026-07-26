<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('users.manage');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            UserService::create(
                trim((string) $_POST['username']),
                trim((string) $_POST['email']),
                (string) $_POST['password'],
                (string) $_POST['role'],
                $user['id']
            );
            flash('success', 'User panel dibuat.');
        } elseif ($action === 'toggle') {
            UserService::setActive((int) $_POST['id'], $_POST['active'] === '1', $user['id']);
            flash('success', 'Status user diperbarui.');
        } elseif ($action === 'role') {
            UserService::changeRole((int) $_POST['id'], (string) $_POST['role'], $user['id']);
            flash('success', 'Role user diperbarui.');
        } elseif ($action === 'password') {
            UserService::changePassword((int) $_POST['id'], (string) $_POST['password'], $user['id']);
            flash('success', 'Password user diperbarui.');
        } elseif ($action === 'delete') {
            UserService::delete((int) $_POST['id'], $user['id']);
            flash('success', 'User dihapus.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/users');
}

$users = UserService::listUsers();
$roles = UserService::roles();

$pageTitle = 'Manajemen User';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Manajemen User Panel</h4>
    <p class="text-muted mb-0">Role-Based Access Control (Admin / Operator / Developer / Viewer)</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="bi bi-plus-lg me-1"></i>Tambah User</button>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light"><tr><th>Username</th><th>Email</th><th>Role</th><th>Login Terakhir</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['username']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td>
            <form method="post" class="d-inline-flex gap-1">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="role">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <select name="role" class="form-select form-select-sm" onchange="this.form.submit()" <?= (int) $u['id'] === $user['id'] ? 'disabled' : '' ?>>
                <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $r === $u['role'] ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="small text-muted"><?= e($u['last_login_at'] ?? 'belum pernah') ?></td>
          <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>' ?></td>
          <td class="text-end">
            <?php if ((int) $u['id'] !== $user['id']): ?>
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
              <button class="btn btn-sm btn-outline-secondary"><i class="bi <?= $u['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button>
            </form>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delUser<?= (int) $u['id'] ?>"><i class="bi bi-trash"></i></button>
            <?php else: ?>
              <span class="text-muted small">Akun Anda</span>
            <?php endif; ?>
          </td>
        </tr>
        <div class="modal fade" id="delUser<?= (int) $u['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Hapus User</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <p>Yakin ingin menghapus user <strong><?= e($u['username']) ?></strong>?</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-danger">Hapus</button>
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

<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah User Panel</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" minlength="8" required></div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
              <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
            </select>
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
