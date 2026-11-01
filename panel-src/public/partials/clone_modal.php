<?php
/**
 * Modal "Clone Proyek" bersama (halaman Website & Node.js). Menyalin isi
 * proyek satu Website/Node.js App ke yang lain; bisa dijalankan berulang
 * untuk sinkronisasi perubahan. Expects $cloneTargets
 * (ProjectCloneService::targets()). Buka dengan cloneOpen('<kind>:<id>', '<label>').
 */
?>
<div class="modal fade" id="cloneModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-copy me-1"></i>Clone Proyek</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="clone">
          <input type="hidden" name="src" id="cloneSrc">
          <p class="mb-3">Salin isi proyek <strong id="cloneSrcLabel"></strong> ke:</p>
          <select name="dest" id="cloneDest" class="form-select mb-3" required>
            <option value="">- pilih tujuan -</option>
            <?php foreach ($cloneTargets as $key => $t): ?>
              <option value="<?= e($key) ?>"><?= e($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="mode" value="all" id="cloneModeAll" checked>
              <label class="form-check-label" for="cloneModeAll"><strong>Seluruh proyek</strong></label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="mode" value="selected" id="cloneModeSel">
              <label class="form-check-label" for="cloneModeSel"><strong>Hanya folder/file tertentu</strong></label>
            </div>
          </div>
          <div id="clonePickBox" class="border rounded p-2 mb-3 d-none">
            <div id="cloneEntries" class="small" style="max-height:200px; overflow:auto;"></div>
            <label class="form-label small mt-2 mb-1">Path lain (opsional, satu per baris, relatif ke root proyek)</label>
            <textarea name="extra_paths" class="form-control form-control-sm" rows="2" placeholder="app/Http&#10;resources/views"></textarea>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="with_git" value="1" id="cloneWithGit">
            <label class="form-check-label" for="cloneWithGit">Sertakan folder <code>.git</code> (tujuan jadi deployment Git juga)</label>
          </div>
          <div class="form-text">
            Mode <em>folder tertentu</em> hanya menyalin yang dicentang (posisi folder dipertahankan di tujuan). File di tujuan <strong>ditimpa jika namanya sama</strong>, tidak ada file tujuan yang dihapus.
            <code>node_modules</code>, <code>.env</code> dan <code>ecosystem.config.cjs</code> tidak ikut disalin.
            Jalankan lagi kapan saja untuk menyinkronkan perubahan terbaru; restart app Node.js sesudahnya bila perlu.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Clone</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
window.cloneOpen = function (srcKey, label) {
  document.getElementById('cloneSrc').value = srcKey;
  document.getElementById('cloneModeAll').checked = true;
  cloneToggleMode();
  var box = document.getElementById('cloneEntries');
  box.textContent = 'Memuat...';
  fetch('/ajax_clone_entries?src=' + encodeURIComponent(srcKey), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      box.textContent = '';
      if (!d.ok) { box.textContent = d.error || 'Gagal memuat isi proyek'; return; }
      if (!d.entries.length) { box.textContent = 'Proyek kosong.'; return; }
      d.entries.forEach(function (e, i) {
        var wrap = document.createElement('div');
        wrap.className = 'form-check';
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.name = 'paths[]'; cb.value = e.name;
        cb.className = 'form-check-input'; cb.id = 'clonePath' + i;
        var lb = document.createElement('label');
        lb.className = 'form-check-label'; lb.htmlFor = cb.id;
        lb.textContent = (e.type === 'dir' ? '\uD83D\uDCC1 ' : '\uD83D\uDCC4 ') + e.name;
        wrap.appendChild(cb); wrap.appendChild(lb); box.appendChild(wrap);
      });
    })
    .catch(function () { box.textContent = 'Gagal memuat isi proyek'; });
  document.getElementById('cloneSrcLabel').textContent = label;
  var dest = document.getElementById('cloneDest');
  dest.value = '';
  Array.prototype.forEach.call(dest.options, function (o) { o.hidden = (o.value === srcKey); });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('cloneModal')).show();
};
window.cloneToggleMode = function () {
  document.getElementById('clonePickBox').classList.toggle('d-none', !document.getElementById('cloneModeSel').checked);
};
document.getElementById('cloneModeAll').addEventListener('change', cloneToggleMode);
document.getElementById('cloneModeSel').addEventListener('change', cloneToggleMode);
</script>
