<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('apps.view');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'install') {
            Rbac::require('apps.install');

            $appSlug = (string) ($_POST['app_slug'] ?? '');
            $domain = trim((string) ($_POST['domain'] ?? ''));
            $phpVersion = (string) ($_POST['php_version'] ?? '');
            $createDatabase = isset($_POST['create_database']);
            $dbName = trim((string) ($_POST['db_name'] ?? '')) ?: null;
            $dbUser = trim((string) ($_POST['db_user'] ?? '')) ?: null;
            $dbPassword = (string) ($_POST['db_password'] ?? '') ?: null;
            $appVersion = trim((string) ($_POST['app_version'] ?? '')) ?: null;

            $customZipBytes = null;
            if ($appSlug === 'custom') {
                if (!isset($_FILES['app_zip']) || $_FILES['app_zip']['error'] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Upload ZIP gagal atau tidak ada file dipilih');
                }
                $customZipBytes = (string) file_get_contents($_FILES['app_zip']['tmp_name']);
            }

            $result = AppInstallerService::installApp(
                $appSlug,
                $domain,
                $phpVersion,
                $createDatabase,
                $dbName,
                $dbUser,
                $dbPassword,
                $customZipBytes,
                $appVersion,
                $user['id']
            );

            $msg = "Aplikasi {$result['app_name']} berhasil diinstal ke {$result['domain']}.";
            if ($result['app_slug'] === 'wordpress') {
                $msg .= " Buka http://{$result['domain']}/wp-admin/install.php untuk membuat user admin WordPress pertama.";
            } elseif (!empty($result['db_name'])) {
                $msg .= " Database: {$result['db_name']} / user: {$result['db_user']}"
                    . (!empty($result['db_password']) ? " / password: {$result['db_password']} (catat sekarang, tidak ditampilkan lagi)" : '')
                    . " - lanjutkan instalasi lewat web installer bawaan aplikasi.";
            }
            flash('success', $msg);
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/app_installer');
}

$catalog = AppCatalog::all();
$phpVersions = PhpService::installedVersions();
$installedApps = AppInstallerService::listInstalled();

$tierBadge = [
    AppCatalog::TIER_FULL => 'text-bg-success',
    AppCatalog::TIER_PARTIAL => 'text-bg-info',
    AppCatalog::TIER_GENERIC => 'text-bg-secondary',
];
$tierLabel = [
    AppCatalog::TIER_FULL => 'Otomatis Penuh',
    AppCatalog::TIER_PARTIAL => 'Otomatis Sebagian',
    AppCatalog::TIER_GENERIC => 'Custom',
];

$pageTitle = 'App Installer';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="fw-bold mb-0">App Installer</h4>
    <p class="text-muted mb-0">Instal aplikasi PHP siap pakai ke website baru dengan satu form</p>
  </div>
</div>

<div class="row g-4 mb-4">
  <?php foreach ($catalog as $slug => $app): ?>
  <div class="col-md-4">
    <div class="card stat-card h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi <?= e($app['icon']) ?> fs-3 text-primary"></i>
          <div>
            <div class="fw-semibold"><?= e($app['name']) ?></div>
            <span class="badge <?= e($tierBadge[$app['tier']]) ?>"><?= e($tierLabel[$app['tier']]) ?></span>
            <?php if (!empty($app['versions'])): ?>
              <span class="text-muted small"><?= count($app['versions']) > 1 ? 'Pilih versi saat instal' : 'v' . e($app['versions'][0]['version']) ?></span>
            <?php elseif ($slug === 'wordpress'): ?>
              <span class="text-muted small">Otomatis versi terbaru</span>
            <?php endif; ?>
          </div>
        </div>
        <p class="text-muted small flex-grow-1"><?= e($app['description']) ?></p>
        <?php if (Rbac::can($user['role'], 'apps.install')): ?>
        <button type="button" class="btn btn-primary btn-sm mt-2"
                data-bs-toggle="modal" data-bs-target="#installAppModal"
                data-slug="<?= e($slug) ?>"
                data-name="<?= e($app['name']) ?>"
                data-tier="<?= e($app['tier']) ?>"
                data-requires-db="<?= $app['requires_database'] ? '1' : '0' ?>"
                data-versions="<?= e(json_encode($app['versions'] ?? [])) ?>"
                data-php-min="<?= e($app['php_min'] ?? '') ?>"
                data-php-max="<?= e($app['php_max'] ?? '') ?>">
          <i class="bi bi-download me-1"></i>Instal
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">Aplikasi Terinstall</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Domain</th><th>Aplikasi</th><th>Versi</th><th>Database</th><th>Diinstal</th></tr>
        </thead>
        <tbody>
        <?php if (empty($installedApps)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Belum ada aplikasi terinstall</td></tr>
        <?php endif; ?>
        <?php foreach ($installedApps as $row): ?>
          <tr>
            <td><a href="http://<?= e($row['domain']) ?>" target="_blank" rel="noopener"><?= e($row['domain']) ?></a></td>
            <td><?= e(AppCatalog::get($row['app_slug'])['name'] ?? $row['app_slug']) ?></td>
            <td><?= e($row['app_version'] ?? '-') ?></td>
            <td><?= e($row['db_name'] ?? '-') ?></td>
            <td class="text-muted small"><?= e($row['installed_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted small p-3 mb-0">Hapus aplikasi lewat menu <a href="/websites">Hapus Website</a> pada domain terkait - database & catatan aplikasi ikut dibersihkan otomatis.</p>
  </div>
</div>

<?php if (Rbac::can($user['role'], 'apps.install')): ?>
<div class="modal fade" id="installAppModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Instal <span id="installAppName"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="install">
          <input type="hidden" name="app_slug" id="installAppSlug">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Domain</label>
              <input type="text" name="domain" class="form-control" placeholder="contoh.com" required pattern="^[a-zA-Z0-9.\-]+$">
              <div class="form-text">Website baru akan dibuat otomatis di domain ini.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Versi PHP</label>
              <select name="php_version" id="installPhpVersionSelect" class="form-select" required>
                <?php foreach ($phpVersions as $v): ?>
                  <option value="<?= e($v) ?>">PHP <?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text text-danger d-none" id="installPhpIncompatibleWarning">Tidak ada versi PHP terinstall yang kompatibel dengan pilihan ini.</div>
            </div>

            <div class="col-md-6" id="installVersionSelectField" style="display:none">
              <label class="form-label">Versi Aplikasi</label>
              <select name="app_version" id="installAppVersionSelect" class="form-select"></select>
            </div>
            <div class="col-md-6" id="installWpVersionField" style="display:none">
              <label class="form-label">Versi WordPress (opsional)</label>
              <input type="text" name="app_version" id="installWpVersionInput" class="form-control" placeholder="Kosongkan = terbaru otomatis" pattern="^\d{1,2}\.\d{1,2}(\.\d{1,3})?$">
              <div class="form-text">Kosongkan untuk selalu memakai rilis stabil terbaru (direkomendasikan).</div>
            </div>

            <div class="col-12" id="installZipField" style="display:none">
              <label class="form-label">File ZIP Aplikasi</label>
              <input type="file" name="app_zip" accept=".zip" class="form-control">
            </div>

            <div class="col-12" id="installDbFields" style="display:none">
              <hr>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="create_database" id="installCreateDb" value="1">
                <label class="form-check-label" for="installCreateDb">Buat database untuk aplikasi ini</label>
              </div>
              <div class="row g-3" id="installDbDetailFields" style="display:none">
                <div class="col-md-4">
                  <label class="form-label">Nama Database</label>
                  <input type="text" name="db_name" class="form-control" pattern="^[a-zA-Z0-9_]{1,64}$">
                </div>
                <div class="col-md-4">
                  <label class="form-label">User Database</label>
                  <input type="text" name="db_user" class="form-control" pattern="^[a-zA-Z0-9_]{1,32}$">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Password Database</label>
                  <div class="input-group">
                    <input type="password" name="db_password" id="installDbPassword" class="form-control" minlength="8">
                    <button type="button" class="btn btn-outline-secondary" data-toggle-password-input="installDbPassword" title="Tampilkan/sembunyikan"><i class="bi bi-eye"></i></button>
                    <button type="button" class="btn btn-outline-secondary" data-generate-password="installDbPassword" title="Generate password acak"><i class="bi bi-magic"></i></button>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12" id="installFullTierNote" style="display:none">
              <div class="alert alert-success small mb-0">
                Database dibuat &amp; dikonfigurasi otomatis - tidak perlu diisi manual.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Instal</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('installAppModal').addEventListener('show.bs.modal', function (ev) {
  var btn = ev.relatedTarget;
  var slug = btn.getAttribute('data-slug');
  var tier = btn.getAttribute('data-tier');
  var requiresDb = btn.getAttribute('data-requires-db') === '1';

  // Reset first - a field hidden via CSS is NOT automatically excluded
  // from HTML5 constraint validation (only `disabled` or the `hidden`
  // attribute reliably are), so a stale value typed for a previous
  // app/tier selection can silently block submission later with a
  // console-only "not focusable" error and no visible feedback. Resetting
  // on every open, plus explicitly disabling whatever stays hidden below,
  // closes both paths.
  this.querySelector('form').reset();

  document.getElementById('installAppSlug').value = slug;
  document.getElementById('installAppName').textContent = btn.getAttribute('data-name');

  var zipField = document.getElementById('installZipField');
  var zipInput = zipField.querySelector('input[name="app_zip"]');
  var showZip = (slug === 'custom');
  zipField.style.display = showZip ? '' : 'none';
  zipInput.disabled = !showZip;

  document.getElementById('installFullTierNote').style.display = (tier === 'full') ? '' : 'none';

  // Multi-version apps (joomla/drupal/phpbb): populate the dropdown from
  // the button's data-versions JSON (sourced server-side from AppCatalog,
  // never client-editable). WordPress instead gets a free-text "pick an
  // exact version" field, since it resolves "latest" dynamically rather
  // than from a fixed list - see WordpressInstallerService::downloadSpecificVersion().
  var versions = JSON.parse(btn.getAttribute('data-versions') || '[]');
  var versionSelectField = document.getElementById('installVersionSelectField');
  var versionSelect = document.getElementById('installAppVersionSelect');
  var wpVersionField = document.getElementById('installWpVersionField');
  var wpVersionInput = document.getElementById('installWpVersionInput');

  versionSelect.innerHTML = '';
  if (versions.length > 0) {
    versionSelectField.style.display = '';
    versionSelect.disabled = false;
    versions.forEach(function (v) {
      var opt = document.createElement('option');
      opt.value = v.version;
      opt.textContent = v.label + ' (PHP ' + v.php_min + (v.php_max ? '-' + v.php_max : '+') + ')';
      opt.setAttribute('data-php-min', v.php_min);
      opt.setAttribute('data-php-max', v.php_max || '');
      versionSelect.appendChild(opt);
    });
  } else {
    versionSelectField.style.display = 'none';
    versionSelect.disabled = true;
  }

  var showWpVersion = (slug === 'wordpress');
  wpVersionField.style.display = showWpVersion ? '' : 'none';
  wpVersionInput.disabled = !showWpVersion;

  filterPhpVersionsForApp(btn);

  var dbFields = document.getElementById('installDbFields');
  var dbDetailFields = document.getElementById('installDbDetailFields');
  var createDbCheckbox = document.getElementById('installCreateDb');
  var dbInputs = dbDetailFields.querySelectorAll('input');

  if (tier === 'full') {
    dbFields.style.display = 'none';
    createDbCheckbox.checked = false;
    createDbCheckbox.disabled = true;
    dbInputs.forEach(function (el) { el.disabled = true; });
  } else if (requiresDb) {
    dbFields.style.display = '';
    createDbCheckbox.checked = true;
    createDbCheckbox.disabled = true;
    dbInputs.forEach(function (el) { el.disabled = false; });
  } else {
    dbFields.style.display = '';
    createDbCheckbox.checked = false;
    createDbCheckbox.disabled = false;
    dbInputs.forEach(function (el) { el.disabled = true; });
  }
  dbDetailFields.style.display = createDbCheckbox.checked ? '' : 'none';
});
document.getElementById('installCreateDb').addEventListener('change', function () {
  var dbDetailFields = document.getElementById('installDbDetailFields');
  var show = this.checked;
  dbDetailFields.style.display = show ? '' : 'none';
  dbDetailFields.querySelectorAll('input').forEach(function (el) { el.disabled = !show; });
});

// Disables <option>s in the "Versi PHP" select that fall outside the
// currently selected app version's [php_min, php_max] range, so an admin
// can't submit a combination the server will just reject anyway. This is
// pure UX convenience - AppInstallerService::installApp() re-validates
// the same range server-side regardless.
function filterPhpVersionsForApp(installBtn) {
  var versionSelect = document.getElementById('installAppVersionSelect');
  var phpMin, phpMax;

  if (!versionSelect.disabled && versionSelect.selectedOptions.length > 0) {
    var opt = versionSelect.selectedOptions[0];
    phpMin = opt.getAttribute('data-php-min');
    phpMax = opt.getAttribute('data-php-max') || null;
  } else {
    phpMin = installBtn.getAttribute('data-php-min') || null;
    phpMax = installBtn.getAttribute('data-php-max') || null;
  }

  var phpSelect = document.getElementById('installPhpVersionSelect');
  var warning = document.getElementById('installPhpIncompatibleWarning');
  var anyCompatible = false;
  var firstCompatible = null;

  Array.prototype.forEach.call(phpSelect.options, function (opt) {
    var compatible = !phpMin || phpVersionCompatible(opt.value, phpMin, phpMax);
    opt.disabled = !compatible;
    if (compatible) {
      anyCompatible = true;
      if (firstCompatible === null) firstCompatible = opt.value;
    }
  });

  if (phpMin && phpSelect.selectedOptions.length > 0 && phpSelect.selectedOptions[0].disabled && firstCompatible !== null) {
    phpSelect.value = firstCompatible;
  }
  warning.classList.toggle('d-none', !phpMin || anyCompatible);
}

function phpVersionCompatible(version, min, max) {
  if (phpVersionCompare(version, min) < 0) return false;
  if (max && phpVersionCompare(version, max) > 0) return false;
  return true;
}

function phpVersionCompare(a, b) {
  var pa = a.split('.').map(Number);
  var pb = b.split('.').map(Number);
  for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
    var diff = (pa[i] || 0) - (pb[i] || 0);
    if (diff !== 0) return diff;
  }
  return 0;
}

document.getElementById('installAppVersionSelect').addEventListener('change', function () {
  var slug = document.getElementById('installAppSlug').value;
  var btn = document.querySelector('[data-slug="' + slug + '"][data-bs-target="#installAppModal"]');
  if (btn) filterPhpVersionsForApp(btn);
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
