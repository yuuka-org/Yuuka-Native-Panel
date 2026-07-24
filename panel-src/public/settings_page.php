<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$user = Auth::user();

const LOGIN_LOGO_MAX_BYTES = 1048576; // 1 MB
const LOGIN_LOGO_ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'svg'];

// Must match dashboard.php's $widgetDefaults keys exactly - this is the
// one place an admin edits show/hide + order, dashboard.php only reads.
const DASHBOARD_WIDGET_LABELS = [
    'server_info' => 'Info Server (hostname, OS, uptime, jam)',
    'alarm_banner' => 'Banner Peringatan Alarm',
    'gauges' => 'Gauge CPU / RAM / Disk / Load Average',
    'quick_count' => 'Ringkasan Jumlah (Website/Node.js/Database/Cloudflare)',
    'services_status' => 'Status Layanan',
    'nodejs_table' => 'Tabel Aplikasi Node.js (PM2)',
];

function settings_page_logo_dir(): string
{
    return __DIR__ . '/assets/uploads';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('settings.manage');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_page') {
            $title = trim((string) ($_POST['panel_login_title'] ?? ''));
            if ($title === '' || strlen($title) > 100) {
                throw new InvalidArgumentException('Judul halaman login wajib diisi (maks 100 karakter)');
            }
            SettingsService::set('panel_login_title', $title);

            if (isset($_FILES['login_logo']) && $_FILES['login_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['login_logo']['error'] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Upload logo gagal');
                }
                if ($_FILES['login_logo']['size'] > LOGIN_LOGO_MAX_BYTES) {
                    throw new InvalidArgumentException('Ukuran logo maksimal 1 MB');
                }
                $ext = strtolower((string) pathinfo((string) $_FILES['login_logo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, LOGIN_LOGO_ALLOWED_EXT, true)) {
                    throw new InvalidArgumentException('Format logo harus PNG, JPG, atau SVG');
                }

                $dir = settings_page_logo_dir();
                if (!is_dir($dir)) {
                    mkdir($dir, 0750, true);
                }

                // Old logo may have a different extension than the new
                // upload - remove it so an orphaned file doesn't linger.
                $oldLogo = SettingsService::get('panel_login_logo');
                if ($oldLogo !== '' && Validator::fileBaseName(basename($oldLogo))) {
                    @unlink($dir . '/' . basename($oldLogo));
                }

                // Fixed filename (not the uploader's original name) - the
                // extension is the only thing derived from user input, and
                // it's already whitelist-checked above.
                $filename = "login_logo.{$ext}";
                if (!move_uploaded_file($_FILES['login_logo']['tmp_name'], $dir . '/' . $filename)) {
                    throw new RuntimeException('Gagal menyimpan file logo');
                }
                chmod($dir . '/' . $filename, 0640);
                SettingsService::set('panel_login_logo', 'assets/uploads/' . $filename);
            }
            flash('success', 'Pengaturan halaman login disimpan.');
        } elseif ($action === 'remove_logo') {
            $oldLogo = SettingsService::get('panel_login_logo');
            if ($oldLogo !== '' && Validator::fileBaseName(basename($oldLogo))) {
                @unlink(settings_page_logo_dir() . '/' . basename($oldLogo));
            }
            SettingsService::set('panel_login_logo', '');
            flash('success', 'Logo dihapus, kembali ke ikon default.');
        } elseif ($action === 'update_widgets') {
            $visible = (array) ($_POST['widget_visible'] ?? []);
            $order = (array) ($_POST['widget_order'] ?? []);
            $config = [];
            foreach (array_keys(DASHBOARD_WIDGET_LABELS) as $widgetId) {
                $config[$widgetId] = [
                    'visible' => isset($visible[$widgetId]),
                    'order' => max(1, min(99, (int) ($order[$widgetId] ?? 1))),
                ];
            }
            SettingsService::set('dashboard_widget_config', json_encode($config));
            flash('success', 'Susunan widget Dashboard disimpan.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings_page.php');
}

Rbac::require('settings.manage');

$loginTitle = SettingsService::get('panel_login_title', 'Yuuka Server Panel');
$loginLogo = SettingsService::get('panel_login_logo');

$widgetConfig = [];
$i = 1;
foreach (array_keys(DASHBOARD_WIDGET_LABELS) as $widgetId) {
    $widgetConfig[$widgetId] = ['visible' => true, 'order' => $i++];
}
$widgetConfigDecoded = json_decode(SettingsService::get('dashboard_widget_config'), true);
if (is_array($widgetConfigDecoded)) {
    foreach ($widgetConfig as $widgetId => $default) {
        if (isset($widgetConfigDecoded[$widgetId]) && is_array($widgetConfigDecoded[$widgetId])) {
            $widgetConfig[$widgetId]['visible'] = (bool) ($widgetConfigDecoded[$widgetId]['visible'] ?? $default['visible']);
            $widgetConfig[$widgetId]['order'] = (int) ($widgetConfigDecoded[$widgetId]['order'] ?? $default['order']);
        }
    }
}

$activeSettingsTab = 'page';

$pageTitle = 'Pengaturan - Page';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/settings_nav.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Page</h4>
  <p class="text-muted mb-0">Kustomisasi tampilan halaman login panel.</p>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Halaman Login</div>
      <div class="card-body">
        <?php if ($loginLogo !== ''): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <img src="/<?= e($loginLogo) ?>" alt="Logo login" style="max-height:48px;max-width:120px;object-fit:contain;">
          <form method="post" data-confirm="Hapus logo dan kembali ke ikon default?">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="remove_logo">
            <button class="btn btn-sm btn-outline-danger">Hapus Logo</button>
          </form>
        </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_page">
          <div class="mb-3">
            <label class="form-label">Judul Halaman Login</label>
            <input type="text" name="panel_login_title" class="form-control" value="<?= e($loginTitle) ?>" maxlength="100" required>
            <div class="form-text">Muncul di tab browser dan heading halaman login.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Logo (opsional)</label>
            <input type="file" name="login_logo" class="form-control" accept=".png,.jpg,.jpeg,.svg">
            <div class="form-text">PNG/JPG/SVG, maksimal 1 MB. Kosongkan kalau tidak ingin mengganti. Kalau belum pernah diisi, halaman login pakai ikon default.</div>
          </div>
          <button class="btn btn-primary">Simpan</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Widget Dashboard</div>
      <div class="card-body">
        <p class="text-muted small">Pilih widget yang tampil di Dashboard, dan urutan tampilnya (angka kecil = lebih atas).</p>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_widgets">
          <?php foreach (DASHBOARD_WIDGET_LABELS as $widgetId => $label): ?>
          <div class="d-flex align-items-center gap-2 mb-2 border rounded px-2 py-2">
            <div class="form-check flex-grow-1 mb-0">
              <input class="form-check-input" type="checkbox" name="widget_visible[<?= e($widgetId) ?>]" value="1" id="widget_<?= e($widgetId) ?>" <?= $widgetConfig[$widgetId]['visible'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="widget_<?= e($widgetId) ?>"><?= e($label) ?></label>
            </div>
            <input type="number" name="widget_order[<?= e($widgetId) ?>]" class="form-control form-control-sm" style="width:70px" value="<?= e((string) $widgetConfig[$widgetId]['order']) ?>" min="1" max="99" title="Urutan">
          </div>
          <?php endforeach; ?>
          <button class="btn btn-primary mt-2">Simpan</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
