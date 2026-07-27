<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.view');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            Rbac::require('nodejs.create');

            $envRaw = trim((string) ($_POST['env'] ?? ''));
            $env = $envRaw !== '' ? EnvService::parseDotEnv($envRaw) : [];

            $port = (int) ($_POST['port'] ?? 0);
            if ($port === 0) {
                $port = NodeService::findFreePort() ?? throw new RuntimeException('Tidak ada port kosong tersedia di range 3000-3999');
            }

            // Spasi diganti "_" supaya nama folder deploy (/home/nodeapps/apps/<nama>)
            // tetap valid tanpa membuat user mengetik ulang - Validator::appName()
            // di NodeService masih tetap menolak karakter lain yang tidak diizinkan.
            $appNameInput = (string) preg_replace('/\s+/', '_', trim((string) ($_POST['app_name'] ?? '')));

            $app = NodeService::createApp(
                $appNameInput,
                trim((string) ($_POST['domain'] ?? '')),
                (string) ($_POST['node_version'] ?? ''),
                $port,
                trim((string) ($_POST['start_command'] ?? 'server.js')),
                trim((string) ($_POST['build_command'] ?? '')) ?: null,
                max(1, (int) ($_POST['instances'] ?? 1)),
                (string) ($_POST['exec_mode'] ?? 'fork'),
                isset($_POST['autorestart']),
                isset($_POST['watch']),
                trim((string) ($_POST['max_memory_restart'] ?? '512M')),
                (string) ($_POST['node_env'] ?? 'production'),
                $env,
                $user['id']
            );
            flash('success', "Aplikasi {$app['app_name']} berhasil dijalankan via PM2 (port {$port}).");
        } elseif ($action === 'control') {
            Rbac::require('nodejs.control');
            NodeService::controlApp((int) $_POST['id'], (string) $_POST['control'], $user['id']);
            flash('success', 'Aksi berhasil dijalankan.');
        } elseif ($action === 'delete') {
            Rbac::require('nodejs.delete');
            NodeService::deleteApp((int) $_POST['id'], ($_POST['delete_files'] ?? '') === '1', $user['id']);
            flash('success', 'Aplikasi dihapus.');
        } elseif ($action === 'import') {
            Rbac::require('nodejs.create');
            NodeService::importUnmanaged((string) $_POST['pm2_name'], $user['id']);
            flash('success', 'Aplikasi berhasil diimpor ke panel.');
        } elseif ($action === 'save_pm2') {
            Rbac::require('nodejs.control');
            nodejs_pm2_save();
            flash('success', 'PM2 process list disimpan (akan otomatis start setelah reboot).');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/nodejs');
}

$status = NodeService::combinedStatus();
$nodeVersions = NodeService::allowedNodeVersions();

$pageTitle = 'Node.js Applications';
include __DIR__ . '/partials/header.php';
?>

<?php if (Rbac::can($user['role'], 'website.view')): ?>
<div class="btn-group mb-3">
  <a href="/websites" class="btn btn-sm btn-outline-secondary"><img src="https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/php.svg" alt="" style="width:1em;height:1em;vertical-align:-0.125em;" class="me-1">PHP</a>
  <a href="/nodejs" class="btn btn-sm btn-primary"><img src="https://cdn.jsdelivr.net/npm/simple-icons@v13/icons/nodedotjs.svg" alt="" style="width:1em;height:1em;vertical-align:-0.125em;" class="me-1">Node.js</a>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">Node.js Applications</h4>
    <p class="text-muted mb-0">Dikelola sepenuhnya melalui PM2 (sumber status: <code>pm2 jlist</code>)</p>
  </div>
  <div class="d-flex gap-2">
    <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="save_pm2">
      <button class="btn btn-outline-secondary"><i class="bi bi-save me-1"></i>Save PM2 List</button>
    </form>
    <?php endif; ?>
    <?php if (Rbac::can($user['role'], 'nodejs.create')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAppModal">
      <i class="bi bi-plus-lg me-1"></i>Tambah Aplikasi
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold">Managed by Panel</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>App Name</th><th>Domain</th><th>Status</th><th>CPU</th><th>RAM</th><th>Uptime</th><th>Restarts</th><th>Port</th><th class="text-end">Aksi</th></tr>
        </thead>
        <tbody>
        <?php if (empty($status['managed'])): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">Belum ada aplikasi Node.js terdaftar</td></tr>
        <?php endif; ?>
        <?php foreach ($status['managed'] as $item): $m = $item['meta']; $rt = $item['runtime']; $domainCount = NodeService::countDomains((int) $m['id']); ?>
          <tr>
            <td><?= e($m['app_name']) ?><div class="text-muted small">Node <?= e($m['node_version']) ?></div></td>
            <td>
              <?php if ($m['domain']): ?>
                <a href="http://<?= e($m['domain']) ?>" target="_blank"><?= e($m['domain']) ?></a>
                <?php if ($domainCount > 1): ?><a href="/nodejs_domains?id=<?= (int) $m['id'] ?>" class="badge text-bg-light border text-decoration-none">+<?= $domainCount - 1 ?> lainnya</a><?php endif; ?>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
              <div class="dropdown">
                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-body status-dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                  <span class="status-dot <?= e($item['status']) ?>"></span><?= e($item['status']) ?>
                </button>
                <ul class="dropdown-menu">
                  <li><button type="button" class="dropdown-item" onclick="pctl(<?= (int) $m['id'] ?>,'start')"><i class="bi bi-play-fill me-2 text-success"></i>Start</button></li>
                  <li><button type="button" class="dropdown-item" onclick="pctl(<?= (int) $m['id'] ?>,'restart')"><i class="bi bi-arrow-clockwise me-2 text-warning"></i>Restart</button></li>
                  <li><button type="button" class="dropdown-item" onclick="pctl(<?= (int) $m['id'] ?>,'reload')"><i class="bi bi-arrow-repeat me-2 text-info"></i>Reload</button></li>
                  <li><button type="button" class="dropdown-item" onclick="pctl(<?= (int) $m['id'] ?>,'stop')"><i class="bi bi-stop-fill me-2 text-secondary"></i>Stop</button></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><button type="button" class="dropdown-item" onclick="pctl(<?= (int) $m['id'] ?>,'reset')"><i class="bi bi-arrow-counterclockwise me-2"></i>Reset Restarts</button></li>
                </ul>
              </div>
              <?php else: ?>
              <span class="status-dot <?= e($item['status']) ?>"></span><?= e($item['status']) ?>
              <?php endif; ?>
            </td>
            <td><?= $rt ? e((string) $rt['cpu_percent']) . '%' : '-' ?></td>
            <td><?= $rt ? e((string) round($rt['memory_bytes'] / 1048576, 1)) . ' MB' : '-' ?></td>
            <td><?= $rt && $rt['uptime_ms'] ? e(gmdate('H:i:s', intdiv((int) $rt['uptime_ms'], 1000))) : '-' ?></td>
            <td><?= $rt ? e((string) $rt['restart_count']) : '-' ?></td>
            <td><?= e((string) $m['port']) ?></td>
            <td class="text-end text-nowrap">
              <div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline-secondary" title="Settings" onclick="nodejsOpenSettings(<?= (int) $m['id'] ?>)"><i class="bi bi-gear"></i></button>
                <?php if (Rbac::can($user['role'], 'files.view')): ?>
                <a href="/file_manager?scope=nodeapp&name=<?= urlencode($m['app_name']) ?>" class="btn btn-sm btn-outline-secondary" title="File Manager"><i class="bi bi-folder2-open"></i></a>
                <?php endif; ?>
                <?php if (Rbac::can($user['role'], 'nodejs.delete')): ?>
                <button class="btn btn-sm btn-outline-danger" title="Hapus" data-bs-toggle="modal" data-bs-target="#delApp<?= (int) $m['id'] ?>"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <div class="modal fade" id="delApp<?= (int) $m['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <form method="post">
                <div class="modal-content">
                  <div class="modal-header"><h5 class="modal-title">Hapus Aplikasi</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <p>Yakin ingin menghapus <strong><?= e($m['app_name']) ?></strong> dari PM2?</p>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="delete_files" value="1" id="delF<?= (int) $m['id'] ?>">
                      <label class="form-check-label" for="delF<?= (int) $m['id'] ?>">Hapus juga folder aplikasi di server</label>
                    </div>
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
</div>

<?php if (!empty($status['unmanaged'])): ?>
<div class="card stat-card border-warning">
  <div class="card-header bg-warning-subtle fw-semibold">
    <i class="bi bi-exclamation-triangle me-1"></i>Unmanaged Applications (berjalan di PM2, belum terdaftar di panel)
  </div>
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Process Name</th><th>Status</th><th>PID</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($status['unmanaged'] as $proc): ?>
        <tr>
          <td><?= e($proc['name']) ?></td>
          <td><span class="status-dot <?= e($proc['status']) ?>"></span><?= e($proc['status']) ?></td>
          <td><?= e((string) ($proc['pid'] ?? '-')) ?></td>
          <td class="text-end">
            <?php if (Rbac::can($user['role'], 'nodejs.create')): ?>
            <form method="post" class="d-inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="import">
              <input type="hidden" name="pm2_name" value="<?= e($proc['name']) ?>">
              <button class="btn btn-sm btn-outline-primary">Import to Panel</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="createAppModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Aplikasi Node.js</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nama Aplikasi (PM2 process name)</label>
              <input type="text" name="app_name" id="appNameInput" class="form-control" required pattern="^[a-zA-Z0-9_-]{1,64}$">
              <div class="form-text">Folder deploy: <code id="appNamePathPreview">/home/nodeapps/apps/&lt;nama&gt;</code></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Domain (opsional)</label>
              <input type="text" name="domain" class="form-control" placeholder="app.contoh.com">
            </div>
            <div class="col-md-4">
              <label class="form-label">Versi Node.js</label>
              <select name="node_version" class="form-select">
                <?php foreach ($nodeVersions as $v): ?><option value="<?= e($v) ?>"><?= e($v) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Port internal (kosongkan = otomatis)</label>
              <input type="number" name="port" class="form-control" min="1024" max="65535" placeholder="auto">
            </div>
            <div class="col-md-4">
              <label class="form-label">NODE_ENV</label>
              <select name="node_env" class="form-select">
                <option value="production">production</option>
                <option value="development">development</option>
                <option value="staging">staging</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Start Command (relatif terhadap folder app)</label>
              <input type="text" name="start_command" class="form-control" value="server.js" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Build Command (opsional, dijalankan manual sebelum deploy)</label>
              <input type="text" name="build_command" class="form-control" placeholder="npm run build">
            </div>
            <div class="col-md-3">
              <label class="form-label">Instances</label>
              <input type="number" name="instances" class="form-control" value="1" min="1" max="32">
            </div>
            <div class="col-md-3">
              <label class="form-label">Exec Mode</label>
              <select name="exec_mode" class="form-select">
                <option value="fork">fork</option>
                <option value="cluster">cluster</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Max Memory Restart</label>
              <input type="text" name="max_memory_restart" class="form-control" value="512M">
            </div>
            <div class="col-md-3 d-flex flex-column justify-content-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="autorestart" id="autorestart" checked>
                <label class="form-check-label" for="autorestart">Autorestart</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="watch" id="watch">
                <label class="form-check-label" for="watch">Watch files</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Environment Variables (.env format, satu baris per variabel)</label>
              <textarea name="env" class="form-control" rows="4" placeholder="DATABASE_URL=mysql://...&#10;API_KEY=..."></textarea>
              <div class="form-text">Nilai akan dienkripsi (AES-256-GCM) sebelum disimpan di database.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Deploy via PM2</button>
        </div>
      </div>
    </form>
  </div>
</div>

<form id="ctlForm" method="post" class="d-none">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="control">
  <input type="hidden" name="id" id="ctlId">
  <input type="hidden" name="control" id="ctlAction">
</form>

<div class="modal fade" id="nodejsSettingsModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="bi bi-gear me-1"></i>Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="nodejsSettingsFrame" src="about:blank" style="width:100%; height:75vh; border:0;" title="Settings"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
window.nodejsOpenSettings = function (id) {
  var frame = document.getElementById('nodejsSettingsFrame');
  if (frame) { frame.src = '/nodejs_settings?id=' + id + '&embed=1'; }
  var modalEl = document.getElementById('nodejsSettingsModal');
  if (modalEl && typeof bootstrap !== 'undefined') { bootstrap.Modal.getOrCreateInstance(modalEl).show(); }
};
(function () {
  var modalEl = document.getElementById('nodejsSettingsModal');
  if (!modalEl) { return; }
  // Drops the iframe back to about:blank on close, same reasoning as
  // File Manager's Terminal modal - avoids leaving a stale settings page
  // (and its own polling/state, if any) alive in the background.
  modalEl.addEventListener('hidden.bs.modal', function () {
    var frame = document.getElementById('nodejsSettingsFrame');
    if (frame) { frame.src = 'about:blank'; }
  });
})();
</script>

<script>
(function () {
  var input = document.getElementById('appNameInput');
  var preview = document.getElementById('appNamePathPreview');
  if (!input || !preview) { return; }
  input.addEventListener('input', function () {
    var start = input.selectionStart;
    var sanitized = input.value.replace(/\s+/g, '_');
    if (sanitized !== input.value) {
      input.value = sanitized;
      if (start !== null) { input.setSelectionRange(start, start); }
    }
    preview.textContent = '/home/nodeapps/apps/' + (sanitized || '<nama>');
  });
})();

function pctl(id, action) {
  document.getElementById('ctlId').value = id;
  document.getElementById('ctlAction').value = action;
  document.getElementById('ctlForm').submit();
}

// The Status dropdown lives inside .table-responsive, which sets
// overflow-x:auto specifically so wide tables scroll horizontally on
// small screens - but that same overflow clips anything Bootstrap
// positions with the default `absolute` Popper strategy, including this
// dropdown's own menu (it got visually cut off at the table's edge
// instead of floating above it). `fixed` positions the menu relative to
// the viewport instead of the scrolling table, escaping that clipping -
// set here via the JS API rather than a data-bs-* attribute since
// popperConfig isn't one of Bootstrap's simple data-attribute options.
if (typeof bootstrap !== 'undefined') {
  document.querySelectorAll('.status-dropdown-toggle').forEach(function (el) {
    new bootstrap.Dropdown(el, { popperConfig: { strategy: 'fixed' } });
  });
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
