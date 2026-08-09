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
    Rbac::require('website.create');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_limit_access') {
            NginxService::addLimitAccessRule(
                $id,
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['path_prefix'] ?? '')),
                trim((string) ($_POST['username'] ?? '')),
                (string) ($_POST['password'] ?? ''),
                $user['id']
            );
            flash('success', 'Limit Access ditambahkan.');
        } elseif ($action === 'remove_limit_access') {
            NginxService::removeLimitAccessRule($id, (int) ($_POST['rule_id'] ?? 0), $user['id']);
            flash('success', 'Limit Access dihapus.');
        } elseif ($action === 'add_deny_rule') {
            NginxService::addDenyRule(
                $id,
                trim((string) ($_POST['name'] ?? '')),
                trim((string) ($_POST['path_prefix'] ?? '')),
                trim((string) ($_POST['suffixes'] ?? '')),
                $user['id']
            );
            flash('success', 'Deny Access ditambahkan.');
        } elseif ($action === 'remove_deny_rule') {
            NginxService::removeDenyRule($id, (int) ($_POST['rule_id'] ?? 0), $user['id']);
            flash('success', 'Deny Access dihapus.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/website_limit_access?id=' . $id . $embedSuffix);
}

$limitAccessRules = NginxService::listLimitAccessRules($id);
$denyRules = NginxService::listDenyRules($id);
$activeWebsiteTab = 'limit_access';
$canEdit = Rbac::can($user['role'], 'website.create');

$pageTitle = 'Limit Access - ' . $site['domain'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/website_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Limit Access: <?= e($site['domain']) ?></h4>
    <p class="text-muted mb-0">Batasi akses ke path tertentu dengan Basic Auth, atau blokir ekstensi file tertentu.</p>
  </div>
  <a href="/websites" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<p class="text-muted small">Batasi akses ke path tertentu dengan Basic Auth, atau blokir ekstensi file tertentu.</p>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabLimitAccess">Limit Access</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDenyAccess">Deny Access</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tabLimitAccess">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Limit Access (Basic Auth per path)</span>
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addLimitAccessModal"><i class="bi bi-plus-lg me-1"></i>Tambah</button>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Nama</th><th>Path</th><th>Username</th><th class="text-end">Aksi</th></tr></thead>
          <tbody>
            <?php if (empty($limitAccessRules)): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">Belum ada aturan Limit Access</td></tr>
            <?php endif; ?>
            <?php foreach ($limitAccessRules as $r): ?>
            <tr>
              <td><?= e($r['name']) ?></td>
              <td><code><?= e($r['path_prefix']) ?></code></td>
              <td><?= e($r['username']) ?></td>
              <td class="text-end">
                <?php if ($canEdit): ?>
                <form method="post" class="d-inline" data-confirm="Hapus aturan Limit Access <?= e($r['name']) ?>?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="remove_limit_access">
                  <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tabDenyAccess">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Deny Access (blokir ekstensi per path)</span>
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDenyRuleModal"><i class="bi bi-plus-lg me-1"></i>Tambah</button>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Nama</th><th>Path</th><th>Ekstensi</th><th class="text-end">Aksi</th></tr></thead>
          <tbody>
            <?php if (empty($denyRules)): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">Belum ada aturan Deny Access</td></tr>
            <?php endif; ?>
            <?php foreach ($denyRules as $r): ?>
            <tr>
              <td><?= e($r['name']) ?></td>
              <td><code><?= e($r['path_prefix']) ?></code></td>
              <td><?php foreach (explode(',', $r['suffixes']) as $ext): ?><span class="badge text-bg-light border me-1"><?= e($ext) ?></span><?php endforeach; ?></td>
              <td class="text-end">
                <?php if ($canEdit): ?>
                <form method="post" class="d-inline" data-confirm="Hapus aturan Deny Access <?= e($r['name']) ?>?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="remove_deny_rule">
                  <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addLimitAccessModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Limit Access</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="add_limit_access">
          <div class="mb-3">
            <label class="form-label">Nama</label>
            <input type="text" name="name" class="form-control" placeholder="Admin Area" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Path</label>
            <input type="text" name="path_prefix" class="form-control" placeholder="/admin" required pattern="^/.*">
          </div>
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required minlength="4">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="addDenyRuleModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Deny Access</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="add_deny_rule">
          <div class="mb-3">
            <label class="form-label">Nama</label>
            <input type="text" name="name" class="form-control" placeholder="Blokir script" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Ekstensi (pisahkan dengan koma)</label>
            <input type="text" name="suffixes" class="form-control tag-chip-source" placeholder="php,jsp,sh" required>
            <div class="tag-chip-preview mt-2"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Path</label>
            <input type="text" name="path_prefix" class="form-control" placeholder="/uploads" required pattern="^/.*">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

</div>
</div>

<script src="/assets/js/tag-chip-input.js"></script>
<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
