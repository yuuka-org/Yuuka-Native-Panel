<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('settings.manage');

$user = Auth::user();

if (($_GET['export'] ?? '') === '1') {
    $json = json_encode(SettingsService::allKeys(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $filename = 'yuuka-settings-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = $_POST['action'] ?? '';

    if ($action === 'import') {
        try {
            if (!isset($_FILES['settings_file']) || $_FILES['settings_file']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload file gagal atau tidak ada file dipilih');
            }
            $raw = file_get_contents($_FILES['settings_file']['tmp_name']);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                throw new InvalidArgumentException('File bukan JSON yang valid');
            }

            $applied = 0;
            $skipped = [];
            foreach ($data as $key => $value) {
                if (!is_string($key) || !in_array($key, SettingsService::KNOWN_KEYS, true) || !is_scalar($value)) {
                    $skipped[] = is_string($key) ? $key : '(kunci tidak valid)';
                    continue;
                }
                SettingsService::set($key, (string) $value);
                $applied++;
            }

            $msg = "{$applied} pengaturan diterapkan.";
            if (!empty($skipped)) {
                $msg .= ' Dilewati (kunci tidak dikenal): ' . implode(', ', $skipped) . '.';
            }
            flash($applied > 0 ? 'success' : 'error', $msg);
            ActivityLog::record($user['id'], 'settings.import', "Import pengaturan: {$applied} diterapkan, " . count($skipped) . ' dilewati');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('/settings_migrate.php');
}

$activeSettingsTab = 'migrate';
$pageTitle = 'Pengaturan - Migrate';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/settings_nav.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Migrate</h4>
  <p class="text-muted mb-0">Export/import pengaturan panel (bukan migrasi penuh server) - berguna sebagai cadangan konfigurasi sebelum instalasi ulang.</p>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Export Pengaturan</div>
      <div class="card-body">
        <p class="text-muted small">Download seluruh pengaturan panel (tabel <code>settings</code>) sebagai file JSON.</p>
        <a href="/settings_migrate.php?export=1" class="btn btn-primary"><i class="bi bi-download me-1"></i>Download JSON</a>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Import Pengaturan</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="import">
          <div class="mb-3">
            <input type="file" name="settings_file" class="form-control" accept=".json" required>
            <div class="form-text">Hanya kunci pengaturan yang dikenal panel yang akan diterapkan - kunci lain dilewati.</div>
          </div>
          <button class="btn btn-outline-primary">Import</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
