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
// just when the iframe itself navigates. A single one-time null-out on
// 'load' isn't enough - ttyd (re-)sets it again later too, e.g. once its
// WebSocket connects - but PERMANENTLY locking the property via
// Object.defineProperty (tried here first) is too aggressive: ttyd
// appears to also READ this same property as part of its own connection/
// reconnect bookkeeping, and a getter hardcoded to always return null sent
// the terminal into an unrecoverable auto-refresh loop on a real
// deployment. Just keep re-nulling it with a plain assignment on an
// interval instead - gentler, never stops ttyd from reading/writing it
// normally between resets, and in practice still wins the race against
// the one moment (an actual tab-close/navigation attempt) that matters.
(function () {
  var frame = document.getElementById('terminalFrame');
  if (!frame) { return; }
  frame.addEventListener('load', function () {
    // No cutoff - ttyd re-attaches onbeforeunload on its own (WebSocket
    // (re)connects, etc), and a real terminal session is routinely open
    // far longer than a short warm-up window. A previous version stopped
    // after 40 ticks (~10s), which "worked" only for the first few
    // seconds of any session and then let the warning come back for the
    // rest of it. Keep re-nulling for as long as this panel page/iframe
    // is open - a plain property assignment every 250ms is cheap enough
    // to just never stop.
    setInterval(function () {
      try { frame.contentWindow.onbeforeunload = null; } catch (e) {}
    }, 250);
  });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
