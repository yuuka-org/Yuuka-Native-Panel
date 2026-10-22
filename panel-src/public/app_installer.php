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
        if ($action === 'install' && AppCatalog::exists((string) ($_POST['app_slug'] ?? ''))
            && AppCatalog::runtime((string) $_POST['app_slug']) === 'nodejs'
            && $_POST['app_slug'] === HermesInstallerService::APP_SLUG) {
            Rbac::require('apps.install');

            $result = HermesInstallerService::install(
                (string) ($_POST['node_app_name'] ?? ''),
                (string) ($_POST['domain'] ?? ''),
                (string) ($_POST['dashboard_user'] ?? ''),
                $user['id']
            );

            // Like 9Router's INITIAL_PASSWORD: shown once here, afterwards
            // only in Node.js Apps > Env (stored encrypted, revealable).
            flash('success',
                "Hermes Agent sedang diinstal di latar belakang untuk {$result['domain']}"
                . " (app Node.js \"{$result['app_name']}\", port lokal {$result['port']}) - biasanya 3-10 menit;"
                . " progresnya di Node.js Apps > Logs, dan http://{$result['domain']} menampilkan halaman status sampai selesai."
                . " Login dashboard: {$result['username']} / {$result['password']}"
                . " (catat sekarang; tersimpan juga di Node.js Apps > Env sebagai HERMES_DASHBOARD_BASIC_AUTH_PASSWORD)."
            );
            flash('warning',
                "Langkah lanjutan Hermes: (1) aktifkan SSL untuk {$result['domain']} di Node.js Apps > Domain & SSL,"
                . " lalu di tab Env ubah HERMES_DASHBOARD_PUBLIC_URL menjadi https://{$result['domain']} dan simpan supaya app di-redeploy;"
                . " (2) setelah login, atur provider model & API key-nya dari dashboard Hermes."
            );
        } elseif ($action === 'install' && AppCatalog::exists((string) ($_POST['app_slug'] ?? ''))
            && AppCatalog::runtime((string) $_POST['app_slug']) === 'nodejs') {
            Rbac::require('apps.install');

            $result = NineRouterInstallerService::install(
                (string) ($_POST['node_app_name'] ?? ''),
                (string) ($_POST['domain'] ?? ''),
                trim((string) ($_POST['app_version'] ?? '')) ?: null,
                $user['id']
            );

            // Shown exactly once here - afterwards only retrievable from
            // Node.js Apps > Env (INITIAL_PASSWORD, stored encrypted), and
            // irrelevant anyway once the admin sets their own password
            // inside the 9Router dashboard.
            flash('success',
                "9Router" . ($result['version'] !== null ? " v{$result['version']}" : '') . " berhasil diinstal ke {$result['domain']}"
                . " (app Node.js \"{$result['app_name']}\", port lokal {$result['port']})."
                . " Dashboard: http://{$result['domain']}/dashboard - password awal: {$result['initial_password']}"
                . " (catat sekarang; tersimpan juga di Node.js Apps > Env sebagai INITIAL_PASSWORD, ganti dari dashboard setelah login)."
                . " Endpoint API untuk Claude Code/Cursor/dll: http://{$result['domain']}/v1."
            );
            flash('warning',
                "Langkah lanjutan 9Router: (1) aktifkan SSL untuk {$result['domain']} di Node.js Apps > Domain & SSL,"
                . " (2) di dashboard 9Router aktifkan opsi \"Require API key\" lalu buat API key -"
                . " tanpa itu endpoint /v1 bisa dipakai siapa saja yang tahu domainnya."
            );
        } elseif ($action === 'install') {
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
        } elseif ($action === 'update_9router') {
            Rbac::require('apps.install');

            $newVersion = NineRouterInstallerService::update((int) ($_POST['nodejs_app_id'] ?? 0), null, $user['id']);
            flash('success', '9Router berhasil diupdate' . ($newVersion !== null ? " ke v{$newVersion}" : '') . ' dan dijalankan ulang. Data provider, API key & riwayat pemakaian tidak berubah.');
        } elseif ($action === 'update_hermes') {
            Rbac::require('apps.install');

            HermesInstallerService::update((int) ($_POST['nodejs_app_id'] ?? 0), $user['id']);
            flash('success', 'Update Hermes berjalan di latar belakang (beberapa menit) - app dijalankan ulang otomatis setelah selesai. Pantau progresnya di Node.js Apps > Logs. Config, sesi, skills & memori tidak berubah.');
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
    <p class="text-muted mb-0">Instal aplikasi siap pakai (PHP &amp; Node.js) ke domain baru dengan satu form</p>
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
            <?php if (AppCatalog::runtime($slug) === 'nodejs'): ?>
              <span class="badge text-bg-dark"><?= e($app['runtime_label'] ?? ('Node.js ' . $app['node_version'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($app['versions'])): ?>
              <span class="text-muted small"><?= count($app['versions']) > 1 ? 'Pilih versi saat instal' : 'v' . e($app['versions'][0]['version']) ?></span>
            <?php elseif ($slug === 'wordpress' || AppCatalog::runtime($slug) === 'nodejs'): ?>
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
                data-runtime="<?= e(AppCatalog::runtime($slug)) ?>"
                data-requires-db="<?= $app['requires_database'] ? '1' : '0' ?>"
                data-versions="<?= e(json_encode($app['versions'] ?? [])) ?>"
                data-php-min="<?= e($app['php_min'] ?? '') ?>"
                data-php-max="<?= e($app['php_max'] ?? '') ?>"
                data-node-version="<?= e($app['node_version'] ?? '') ?>"
                data-pin-version="<?= !empty($app['pin_version']) ? '1' : '0' ?>"
                data-dashboard-login="<?= !empty($app['dashboard_login']) ? '1' : '0' ?>"
                data-default-app-name="<?= e($app['default_app_name'] ?? '') ?>"
                data-install-note="<?= e($app['install_note'] ?? '') ?>">
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
          <tr><th>Domain</th><th>Aplikasi</th><th>Versi</th><th>Database</th><th>Diinstal</th><th class="text-end">Aksi</th></tr>
        </thead>
        <tbody>
        <?php if (empty($installedApps)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Belum ada aplikasi terinstall</td></tr>
        <?php endif; ?>
        <?php foreach ($installedApps as $row): ?>
          <?php
            $scheme = (int) $row['ssl_enabled'] === 1 ? 'https' : 'http';
            $isNineRouter = $row['app_slug'] === NineRouterInstallerService::APP_SLUG && $row['nodejs_app_id'] !== null;
            $isHermes = $row['app_slug'] === HermesInstallerService::APP_SLUG && $row['nodejs_app_id'] !== null;
          ?>
          <tr>
            <td>
              <?php if ($row['domain'] !== null): ?>
                <a href="<?= e($scheme) ?>://<?= e($row['domain']) ?><?= $isNineRouter ? '/dashboard' : '' ?>" target="_blank" rel="noopener"><?= e($row['domain']) ?></a>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td>
              <?= e(AppCatalog::get($row['app_slug'])['name'] ?? $row['app_slug']) ?>
              <?php if ($row['nodejs_app_id'] !== null): ?>
                <div class="text-muted small">Node.js: <?= e($row['nodejs_app_name'] ?? '') ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($row['app_version'] ?? '-') ?></td>
            <td><?= e($row['db_name'] ?? '-') ?></td>
            <td class="text-muted small"><?= e($row['installed_at']) ?></td>
            <td class="text-end text-nowrap">
              <?php if ($row['nodejs_app_id'] !== null && Rbac::can($user['role'], 'nodejs.view')): ?>
                <a href="/nodejs_settings?id=<?= (int) $row['nodejs_app_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Kelola di Node.js Apps"><i class="bi bi-gear"></i></a>
              <?php endif; ?>
              <?php if ($isNineRouter && Rbac::can($user['role'], 'apps.install')): ?>
                <form method="post" class="d-inline" data-confirm="Update 9Router ke versi terbaru dari npm? Aplikasi berhenti sebentar (biasanya kurang dari 1 menit) selama update. Data provider, API key &amp; riwayat pemakaian tidak berubah.">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="update_9router">
                  <input type="hidden" name="nodejs_app_id" value="<?= (int) $row['nodejs_app_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-primary" title="Update ke versi terbaru"><i class="bi bi-arrow-up-circle me-1"></i>Update</button>
                </form>
              <?php endif; ?>
              <?php if ($isHermes && Rbac::can($user['role'], 'apps.install')): ?>
                <form method="post" class="d-inline" data-confirm="Update Hermes ke rilis stable terbaru? Installer resmi berjalan di latar belakang beberapa menit, lalu app dijalankan ulang otomatis. Config, sesi, skills &amp; memori tidak berubah.">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="update_hermes">
                  <input type="hidden" name="nodejs_app_id" value="<?= (int) $row['nodejs_app_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-primary" title="Update ke rilis stable terbaru"><i class="bi bi-arrow-up-circle me-1"></i>Update</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted small p-3 mb-0">Hapus aplikasi PHP lewat menu <a href="/websites">Hapus Website</a> pada domain terkait, aplikasi Node.js (9Router) lewat menu <a href="/nodejs">Node.js Apps</a> - database &amp; catatan aplikasi ikut dibersihkan otomatis.</p>
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
              <div class="form-text" id="installDomainHelp">Website baru akan dibuat otomatis di domain ini.</div>
            </div>
            <div class="col-md-6" id="installPhpVersionField">
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

            <div class="col-md-6" id="installNodeAppNameField" style="display:none">
              <label class="form-label">Nama Aplikasi (PM2)</label>
              <input type="text" name="node_app_name" id="installNodeAppNameInput" class="form-control" required pattern="^[a-zA-Z0-9_\-]{1,64}$" maxlength="64">
              <div class="form-text">Jadi folder <code>/home/nodeapps/apps/&lt;nama&gt;</code> &amp; nama proses PM2 - huruf, angka, <code>-</code>, <code>_</code>.</div>
            </div>
            <div class="col-md-6" id="installDashboardUserField" style="display:none">
              <label class="form-label">Username Dashboard</label>
              <input type="text" name="dashboard_user" id="installDashboardUserInput" class="form-control" value="admin" required pattern="^[a-zA-Z0-9_.\-]{3,64}$" maxlength="64">
              <div class="form-text">Untuk login bawaan dashboard. Password dibuat otomatis &amp; ditampilkan sekali.</div>
            </div>
            <div class="col-md-6" id="installNodeVersionField" style="display:none">
              <label class="form-label">Versi 9Router (opsional)</label>
              <input type="text" name="app_version" id="installNodeVersionInput" class="form-control" placeholder="Kosongkan = terbaru otomatis" pattern="^\d{1,3}\.\d{1,3}\.\d{1,4}$">
              <div class="form-text">Format <code>x.y.z</code> sesuai rilis npm. Kosongkan untuk rilis terbaru (direkomendasikan).</div>
            </div>
            <div class="col-12" id="installNodeNote" style="display:none">
              <div class="alert alert-success small mb-0" id="installNodeNoteText"></div>
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

  // Node.js-runtime apps (9Router, Hermes) are a different shape entirely:
  // no PHP version/database, but a PM2 app name plus per-app extras from
  // the catalog (9Router: optional npm version; Hermes: dashboard username).
  // Hidden fields are DISABLED (not just hidden) for the same reason
  // explained above - a hidden-but-enabled `required` field like the PHP
  // select would otherwise block submission or post a bogus value.
  var isNode = btn.getAttribute('data-runtime') === 'nodejs';
  document.getElementById('installPhpVersionField').style.display = isNode ? 'none' : '';
  document.getElementById('installPhpVersionSelect').disabled = isNode;
  var pinVersion = isNode && btn.getAttribute('data-pin-version') === '1';
  var dashboardLogin = isNode && btn.getAttribute('data-dashboard-login') === '1';
  ['installNodeAppNameField', 'installNodeNote'].forEach(function (id) {
    document.getElementById(id).style.display = isNode ? '' : 'none';
  });
  document.getElementById('installNodeVersionField').style.display = pinVersion ? '' : 'none';
  document.getElementById('installDashboardUserField').style.display = dashboardLogin ? '' : 'none';
  document.getElementById('installNodeAppNameInput').disabled = !isNode;
  document.getElementById('installNodeVersionInput').disabled = !pinVersion;
  document.getElementById('installDashboardUserInput').disabled = !dashboardLogin;
  if (isNode) {
    document.getElementById('installNodeAppNameInput').value = btn.getAttribute('data-default-app-name') || '';
    document.getElementById('installNodeNoteText').textContent = btn.getAttribute('data-install-note') || '';
  }
  document.getElementById('installDomainHelp').textContent = isNode
    ? 'Domain/subdomain khusus untuk dashboard & endpoint API (contoh: ai.contoh.com) - arahkan DNS-nya ke server ini dulu.'
    : 'Website baru akan dibuat otomatis di domain ini.';

  var zipField = document.getElementById('installZipField');
  var zipInput = zipField.querySelector('input[name="app_zip"]');
  var showZip = (slug === 'custom');
  zipField.style.display = showZip ? '' : 'none';
  zipInput.disabled = !showZip;

  document.getElementById('installFullTierNote').style.display = (tier === 'full' && !isNode) ? '' : 'none';

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

  if (isNode) {
    document.getElementById('installPhpIncompatibleWarning').classList.add('d-none');
  } else {
    filterPhpVersionsForApp(btn);
  }

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
