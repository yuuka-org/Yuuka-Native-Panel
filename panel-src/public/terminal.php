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
    <iframe id="terminalFrame" src="/terminal/" style="width:100%; height:75vh; border:0; border-radius:0.9rem;" title="Terminal"></iframe>
  </div>
</div>

<script>
// ttyd's own bundled frontend (not panel code) sets window.onbeforeunload
// inside the iframe to warn about losing the session - since /terminal/ is
// proxied on the SAME origin as the panel, that handler also fires (and
// blocks/prompts) when navigating AWAY from this panel page entirely, not
// just when the iframe itself navigates. Neutralize it once ttyd's own
// script has had a chance to set it.
(function () {
  var frame = document.getElementById('terminalFrame');
  if (!frame) { return; }
  frame.addEventListener('load', function () {
    try { frame.contentWindow.onbeforeunload = null; } catch (e) {}
  });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
