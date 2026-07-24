<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('terminal.access');

$pageTitle = 'Terminal';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">Terminal</h4>
    <p class="text-muted mb-0">Dibatasi ke <code>/var/www</code> dan <code>/home/nodeapps/apps</code> - path lain tidak ter-mount sama sekali, bukan cuma ditolak izin.</p>
  </div>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <iframe src="/terminal/" style="width:100%; height:75vh; border:0; border-radius:0.9rem;" title="Terminal"></iframe>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
