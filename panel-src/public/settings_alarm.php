<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    Rbac::require('settings.manage');

    try {
        SettingsService::set('cpu_alert_threshold', (string) max(1, min(100, (int) ($_POST['cpu_alert_threshold'] ?? 85))));
        SettingsService::set('mem_alert_threshold', (string) max(1, min(100, (int) ($_POST['mem_alert_threshold'] ?? 85))));
        SettingsService::set('restart_alert_threshold', (string) max(1, (int) ($_POST['restart_alert_threshold'] ?? 10)));

        $webhookUrl = trim((string) ($_POST['alarm_webhook_url'] ?? ''));
        if ($webhookUrl !== '' && !Validator::healthCheckUrl($webhookUrl)) {
            throw new InvalidArgumentException('URL webhook tidak valid (harus http/https)');
        }
        SettingsService::set('alarm_webhook_url', $webhookUrl);

        flash('success', 'Pengaturan alarm disimpan.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/settings_alarm.php');
}

Rbac::require('settings.manage');

$cpuThreshold = SettingsService::get('cpu_alert_threshold', '85');
$memThreshold = SettingsService::get('mem_alert_threshold', '85');
$restartThreshold = SettingsService::get('restart_alert_threshold', '10');
$alarmWebhookUrl = SettingsService::get('alarm_webhook_url');
$activeSettingsTab = 'alarm';

$pageTitle = 'Pengaturan - Alarm';
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/settings_nav.php';
?>

<div class="mb-4">
  <h4 class="fw-bold mb-0">Alarm</h4>
  <p class="text-muted mb-0">Ambang batas yang memicu peringatan di Dashboard.</p>
</div>

<div class="card stat-card" style="max-width:480px">
  <div class="card-body">
    <form method="post">
      <?= Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">Threshold Alert CPU (%)</label>
        <input type="number" name="cpu_alert_threshold" class="form-control" value="<?= e($cpuThreshold) ?>" min="1" max="100">
      </div>
      <div class="mb-3">
        <label class="form-label">Threshold Alert Memory (%)</label>
        <input type="number" name="mem_alert_threshold" class="form-control" value="<?= e($memThreshold) ?>" min="1" max="100">
      </div>
      <div class="mb-3">
        <label class="form-label">Threshold Alert Restart Count (PM2)</label>
        <input type="number" name="restart_alert_threshold" class="form-control" value="<?= e($restartThreshold) ?>" min="1">
        <div class="form-text">Kalau jumlah restart aplikasi Node.js melebihi ini, namanya akan disebutkan di banner peringatan Dashboard.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Webhook Notifikasi (opsional)</label>
        <input type="url" name="alarm_webhook_url" class="form-control" value="<?= e($alarmWebhookUrl) ?>" placeholder="https://discord.com/api/webhooks/... atau endpoint sendiri">
        <div class="form-text">
          Kalau diisi, panel kirim POST JSON <code>{event, message, timestamp}</code> ke URL ini
          saat ambang batas terlampaui (paling cepat tiap 15 menit per kondisi, tidak akan
          membanjiri saat halaman Dashboard di-refresh berkali-kali).
        </div>
      </div>
      <button class="btn btn-primary">Simpan</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
