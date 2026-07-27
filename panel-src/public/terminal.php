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
// 'load' wasn't enough - ttyd (re-)sets it again later too, e.g. once its
// WebSocket connects, well after the iframe's load event already fired.
// Patch the iframe's window as early as possible and keep it patched:
// both the onbeforeunload PROPERTY (redefined so any future assignment
// is silently swallowed, not just cleared once) and addEventListener
// (in case ttyd registers via that path instead) are neutralized.
(function () {
  var frame = document.getElementById('terminalFrame');
  if (!frame) { return; }
  function neutralizeBeforeUnload() {
    var win = frame.contentWindow;
    if (!win) { return; }
    try {
      win.onbeforeunload = null;
      Object.defineProperty(win, 'onbeforeunload', {
        configurable: true,
        get: function () { return null; },
        set: function () {}
      });
    } catch (e) {}
    try {
      var realAdd = win.EventTarget.prototype.addEventListener;
      win.addEventListener = function (type, listener, options) {
        if (type === 'beforeunload') { return; }
        return realAdd.call(win, type, listener, options);
      };
    } catch (e) {}
  }
  frame.addEventListener('load', neutralizeBeforeUnload);
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
