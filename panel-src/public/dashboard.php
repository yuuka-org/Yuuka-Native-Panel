<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$summary = SystemService::summary();
$serverInfo = SystemService::serverInfo();
$services = SystemService::serviceStatuses();
$nodejsStatus = NodeService::combinedStatus();
$websiteCount = count(NginxService::listWebsites());
$dbCount = count(DatabaseService::listRegistry());
$cloudflare = CloudflareService::status();

$cpuAlertThreshold = (float) SettingsService::get('cpu_alert_threshold', '85');
$memAlertThreshold = (float) SettingsService::get('mem_alert_threshold', '85');
$restartAlertThreshold = (int) SettingsService::get('restart_alert_threshold', '10');

// Restart count isn't part of ajax_stats.php's live payload (PM2 restart
// counts don't need second-by-second polling the way CPU/RAM do), so this
// is computed once at page load - CPU/RAM alerts, by contrast, are
// recomputed by renderAlarmBanner() below on every 5s panel:refresh tick.
$restartOffenders = [];
foreach ($nodejsStatus['managed'] as $item) {
    $rt = $item['runtime'];
    if ($rt !== null && (int) $rt['restart_count'] > $restartAlertThreshold) {
        $restartOffenders[] = $item['meta']['app_name'];
    }
}
$restartAlertMessage = empty($restartOffenders)
    ? ''
    : 'Restart count tinggi (>' . $restartAlertThreshold . '): ' . implode(', ', $restartOffenders);

$pageTitle = 'Dashboard';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Dashboard</h4>
    <p class="text-muted mb-0">Ringkasan status server secara real-time</p>
  </div>
  <div id="statsBlock" data-refresh-url="/ajax_stats.php" data-refresh-interval="5000"></div>
</div>

<div id="alarmBanner"></div>

<div class="card stat-card mb-4">
  <div class="card-body py-3">
    <div class="server-info-strip">
      <span><span class="label">Hostname</span><?= e($serverInfo['hostname']) ?></span>
      <span><span class="label">OS</span><?= e($serverInfo['os']) ?></span>
      <span><span class="label">Kernel</span><?= e($serverInfo['kernel']) ?></span>
      <span><span class="label">PHP</span><?= e($serverInfo['php_version']) ?></span>
      <span><span class="label">Uptime</span><?= e($summary['uptime']) ?></span>
      <span><span class="label">Waktu Server</span><span id="serverClock">-</span></span>
    </div>
  </div>
</div>

<div class="row g-3 mb-4" id="statCards">
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body text-center">
        <div class="gauge" id="cpuGauge"><span class="gauge-value" id="cpuValue"><?= e((string) $summary['cpu_percent']) ?>%</span></div>
        <div class="text-muted small mt-2">CPU Usage</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body text-center">
        <div class="gauge" id="ramGauge"><span class="gauge-value" id="ramValue"><?= e((string) $summary['ram']['percent']) ?>%</span></div>
        <div class="text-muted small mt-2">RAM Usage</div>
        <div class="small text-muted" id="ramDetail"><?= e((string) $summary['ram']['used_mb']) ?> / <?= e((string) $summary['ram']['total_mb']) ?> MB</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body text-center">
        <div class="gauge" id="diskGauge"><span class="gauge-value" id="diskValue"><?= e((string) $summary['disk']['percent']) ?>%</span></div>
        <div class="text-muted small mt-2">Disk Usage</div>
        <div class="small text-muted" id="diskDetail"><?= e((string) $summary['disk']['used_gb']) ?> / <?= e((string) $summary['disk']['total_gb']) ?> GB</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body d-flex flex-column align-items-center justify-content-center h-100">
        <i class="bi bi-graph-up fs-1 text-primary mb-2"></i>
        <div class="stat-value" id="loadValue"><?= e(implode(' / ', array_map(fn($v) => round($v, 2), $summary['load']))) ?></div>
        <div class="text-muted small">Load Average (1/5/15m)</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="quick-count-tile">
        <div class="icon-box bg-primary"><i class="bi bi-globe2"></i></div>
        <div>
          <div class="fs-4 fw-bold"><?= e((string) $websiteCount) ?></div>
          <div class="text-muted small">Website PHP</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="quick-count-tile">
        <div class="icon-box bg-success"><i class="bi bi-diagram-3"></i></div>
        <div>
          <div class="fs-4 fw-bold"><?= e((string) count($nodejsStatus['managed'])) ?></div>
          <div class="text-muted small">Aplikasi Node.js</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="quick-count-tile">
        <div class="icon-box bg-warning"><i class="bi bi-database"></i></div>
        <div>
          <div class="fs-4 fw-bold"><?= e((string) $dbCount) ?></div>
          <div class="text-muted small">Database</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card">
      <div class="quick-count-tile">
        <div class="icon-box bg-info"><i class="bi bi-cloud"></i></div>
        <div>
          <div class="fs-5 fw-bold">
            <?php if (!$cloudflare['configured']): ?>
              <span class="text-muted fs-6">Belum diatur</span>
            <?php else: ?>
              <span class="status-dot <?= e($cloudflare['status']) ?>"></span><?= e(ucfirst($cloudflare['status'])) ?>
            <?php endif; ?>
          </div>
          <div class="text-muted small">Cloudflare Tunnel</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold">Status Layanan</div>
  <div class="card-body">
    <div class="row g-2">
      <?php foreach ($services as $name => $status): ?>
        <div class="col-6 col-md-3">
          <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2">
            <span class="small"><?= e($name) ?></span>
            <span class="small"><span class="status-dot <?= e($status) ?>"></span><?= e($status) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span>Aplikasi Node.js (via PM2)</span>
    <a href="/nodejs.php" class="btn btn-sm btn-outline-primary">Kelola semua</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>App Name</th><th>Status</th><th>CPU</th><th>RAM</th><th>Restarts</th></tr>
        </thead>
        <tbody>
          <?php if (empty($nodejsStatus['managed'])): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada aplikasi Node.js terdaftar</td></tr>
          <?php endif; ?>
          <?php foreach ($nodejsStatus['managed'] as $item): $rt = $item['runtime']; ?>
            <tr>
              <td><?= e($item['meta']['app_name']) ?></td>
              <td><span class="status-dot <?= e($item['status']) ?>"></span><?= e($item['status']) ?></td>
              <td><?= $rt ? e((string) $rt['cpu_percent']) . '%' : '-' ?></td>
              <td><?= $rt ? e((string) round($rt['memory_bytes'] / 1048576, 1)) . ' MB' : '-' ?></td>
              <td><?= $rt ? e((string) $rt['restart_count']) : '-' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Color-codes a gauge ring the same way aaPanel does: green under 60%,
// orange 60-85%, red above that - a quick "does this need attention"
// signal without having to read the exact number.
function gaugeColorFor(percent) {
  if (percent >= 85) return '#ef4444';
  if (percent >= 60) return '#f59e0b';
  return '#22c55e';
}
function setGauge(id, valueId, percent) {
  var gauge = document.getElementById(id);
  var value = document.getElementById(valueId);
  if (!gauge || !value) return;
  gauge.style.setProperty('--percent', Math.max(0, Math.min(100, percent)));
  gauge.style.setProperty('--gauge-color', gaugeColorFor(percent));
  value.textContent = percent + '%';
}
setGauge('cpuGauge', 'cpuValue', <?= (float) $summary['cpu_percent'] ?>);
setGauge('ramGauge', 'ramValue', <?= (float) $summary['ram']['percent'] ?>);
setGauge('diskGauge', 'diskValue', <?= (float) $summary['disk']['percent'] ?>);

// Threshold values come from Settings > Alarm (SettingsService) - restart
// count doesn't change every 5s like CPU/RAM, so its message is computed
// once server-side and just carried along on every re-render here.
var ALARM_CPU_THRESHOLD = <?= json_encode($cpuAlertThreshold) ?>;
var ALARM_MEM_THRESHOLD = <?= json_encode($memAlertThreshold) ?>;
var ALARM_RESTART_MESSAGE = <?= json_encode($restartAlertMessage) ?>;

function renderAlarmBanner(cpuPercent, ramPercent) {
  var el = document.getElementById('alarmBanner');
  if (!el) return;
  var messages = [];
  if (cpuPercent > ALARM_CPU_THRESHOLD) {
    messages.push('CPU ' + cpuPercent.toFixed(1) + '% melebihi ambang ' + ALARM_CPU_THRESHOLD + '%');
  }
  if (ramPercent > ALARM_MEM_THRESHOLD) {
    messages.push('RAM ' + ramPercent.toFixed(1) + '% melebihi ambang ' + ALARM_MEM_THRESHOLD + '%');
  }
  if (ALARM_RESTART_MESSAGE) {
    messages.push(ALARM_RESTART_MESSAGE);
  }
  if (messages.length === 0) {
    el.innerHTML = '';
    return;
  }
  el.innerHTML = '<div class="alert alert-danger d-flex align-items-start gap-2 mb-3">'
    + '<i class="bi bi-exclamation-triangle-fill mt-1"></i><div>' + messages.join(' &middot; ') + '</div></div>';
}
renderAlarmBanner(<?= (float) $summary['cpu_percent'] ?>, <?= (float) $summary['ram']['percent'] ?>);

document.getElementById('statsBlock').addEventListener('panel:refresh', function (e) {
  var d = e.detail;
  if (!d || !d.ok) return;
  setGauge('cpuGauge', 'cpuValue', d.cpu_percent);
  setGauge('ramGauge', 'ramValue', d.ram.percent);
  document.getElementById('ramDetail').textContent = d.ram.used_mb + ' / ' + d.ram.total_mb + ' MB';
  setGauge('diskGauge', 'diskValue', d.disk.percent);
  document.getElementById('diskDetail').textContent = d.disk.used_gb + ' / ' + d.disk.total_gb + ' GB';
  document.getElementById('loadValue').textContent = d.load.map(function(v){return Math.round(v*100)/100;}).join(' / ');
  renderAlarmBanner(d.cpu_percent, d.ram.percent);
});

// Ticks forward client-side from the server's actual clock reading at
// render time (bootstrap.php sets UTC as the app's canonical timezone),
// so this is genuinely "server time", not just the visitor's browser
// clock relabeled - no repeated AJAX call needed to keep it live.
(function () {
  var el = document.getElementById('serverClock');
  if (!el) return;
  var serverEpochMsAtRender = <?= time() * 1000 ?>;
  var clientMsAtRender = Date.now();
  var tick = function () {
    var now = new Date(serverEpochMsAtRender + (Date.now() - clientMsAtRender));
    el.textContent = now.toLocaleString('id-ID', { hour12: false, timeZone: 'UTC' }) + ' UTC';
  };
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
