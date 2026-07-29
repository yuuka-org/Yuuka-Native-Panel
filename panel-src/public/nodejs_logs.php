<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.logs.view');

$user = Auth::user();
$id = (int) ($_GET['id'] ?? 0);
$embed = ($_GET['embed'] ?? '') === '1';
$embedSuffix = $embed ? '&embed=1' : '';
$app = NodeService::find($id);
if ($app === null) {
    flash('error', 'Aplikasi tidak ditemukan');
    redirect('/nodejs');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    if (($_POST['action'] ?? '') === 'clear') {
        Rbac::require('nodejs.control');
        NodeService::clearLogs($id);
        flash('success', 'Log dibersihkan.');
    }
    redirect('/nodejs_logs?id=' . $id . $embedSuffix);
}

$lines = min(1000, max(10, (int) ($_GET['lines'] ?? 150)));
$archiveFile = trim((string) ($_GET['file'] ?? ''));
$viewingArchive = $archiveFile !== '';

if ($viewingArchive) {
    try {
        $logOutput = NodeService::getArchivedLog($id, $archiveFile, $lines);
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
        redirect('/nodejs_logs?id=' . $id . $embedSuffix);
    }
    $startOffset = null;
} else {
    $logOutput = NodeService::getLogs($id, $lines);
    // Live tail (nodejs_logs_stream.php) starts exactly here, so it never
    // re-sends anything already shown by the plain GET above.
    $startOffset = NodeService::currentLogOffset($id);
}

$archives = NodeService::listLogArchives($id);
$activeNodejsTab = 'logs';

$pageTitle = 'Logs - ' . $app['app_name'];
include __DIR__ . ($embed ? '/partials/embed_header.php' : '/partials/header.php');
?>
<div class="d-flex">
<?php include __DIR__ . '/partials/nodejs_settings_nav.php'; ?>
<div class="flex-grow-1">

<?php if (!$embed): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Logs: <?= e($app['app_name']) ?></h4>
    <p class="text-muted mb-0">Output gabungan stdout+stderr, langsung dari file log PM2 (tanpa prefix <code>N|nama |</code>) - berjalan (live) selama halaman ini terbuka.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="/nodejs" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
    <form method="post" data-confirm="Kosongkan seluruh log aplikasi ini?">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="clear">
      <button class="btn btn-outline-danger"><i class="bi bi-eraser me-1"></i>Clear Logs</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<?php include __DIR__ . '/partials/flash.php'; ?>
<?php if (Rbac::can($user['role'], 'nodejs.control')): ?>
<form method="post" class="mb-3" data-confirm="Kosongkan seluruh log aplikasi ini?">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="clear">
  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-eraser me-1"></i>Clear Logs</button>
</form>
<?php endif; ?>
<?php endif; ?>

<form method="get" class="d-flex gap-2 mb-3 flex-wrap align-items-center">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <select name="file" class="form-select" style="max-width:260px" onchange="this.form.submit()">
    <option value="">Log saat ini (live)</option>
    <?php foreach ($archives as $a): ?>
      <option value="<?= e($a['file']) ?>" <?= $a['file'] === $archiveFile ? 'selected' : '' ?>>Run sebelumnya - <?= e($a['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="lines" class="form-select" style="max-width:160px" onchange="this.form.submit()">
    <?php foreach ([50, 100, 150, 300, 500, 1000] as $opt): ?>
      <option value="<?= $opt ?>" <?= $opt === $lines ? 'selected' : '' ?>><?= $opt ?> baris</option>
    <?php endforeach; ?>
  </select>
  <?php if (!$viewingArchive): ?>
  <span class="badge text-bg-success" id="logLiveBadge"><i class="bi bi-broadcast me-1"></i>Live</span>
  <?php endif; ?>
</form>

<div class="log-viewer" id="logViewerBox"><?= e($logOutput ?: 'Belum ada output log.') ?></div>

</div>
</div>

<?php if (!$viewingArchive): ?>
<script>
(function () {
  var box = document.getElementById('logViewerBox');
  var badge = document.getElementById('logLiveBadge');
  if (!box) { return; }
  // "Belum ada output log." placeholder from the PHP render above must be
  // cleared out the moment real content starts arriving, otherwise it
  // just sits there above the live lines forever.
  var placeholderShown = box.textContent.trim() === 'Belum ada output log.';
  var offset = <?= json_encode($startOffset) ?>;
  var streamUrl = '/nodejs_logs_stream.php?id=<?= (int) $id ?>&offset=';
  var es = null;
  var reconnectTimer = null;

  function appendContent(text) {
    if (!text) { return; }
    if (placeholderShown) {
      box.textContent = '';
      placeholderShown = false;
    }
    var atBottom = box.scrollTop + box.clientHeight >= box.scrollHeight - 20;
    box.textContent += text;
    if (atBottom) { box.scrollTop = box.scrollHeight; }
  }

  function connect() {
    if (es) { es.close(); }
    es = new EventSource(streamUrl + offset);
    es.addEventListener('log', function (e) {
      try { appendContent(JSON.parse(e.data).content); } catch (err) {}
    });
    es.addEventListener('offset', function (e) {
      try { offset = JSON.parse(e.data).offset; } catch (err) {}
    });
    es.onopen = function () {
      if (badge) { badge.className = 'badge text-bg-success'; badge.innerHTML = '<i class="bi bi-broadcast me-1"></i>Live'; }
    };
    // The server deliberately ends the stream every ~55s (see sse_loop in
    // app/helpers/sse.php) to avoid tying up a PHP-FPM worker forever -
    // that's a normal close, not an error, so just reconnect starting
    // from whatever offset we last saw. EventSource's OWN built-in
    // auto-reconnect is intentionally not relied on here: it always
    // re-requests the exact original URL, which would replay from the
    // stale starting offset and duplicate everything shown since.
    es.onerror = function () {
      es.close();
      if (badge) { badge.className = 'badge text-bg-secondary'; badge.innerHTML = '<i class="bi bi-broadcast me-1"></i>Menyambung ulang...'; }
      window.clearTimeout(reconnectTimer);
      reconnectTimer = window.setTimeout(connect, 2000);
    };
  }

  connect();
  document.addEventListener('visibilitychange', function () {
    // Pause while the tab/embed iframe is hidden (modal closed, other tab
    // focused) - no point holding a connection open nobody is watching.
    if (document.hidden) {
      window.clearTimeout(reconnectTimer);
      if (es) { es.close(); es = null; }
    } else if (!es) {
      connect();
    }
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . ($embed ? '/partials/embed_footer.php' : '/partials/footer.php'); ?>
