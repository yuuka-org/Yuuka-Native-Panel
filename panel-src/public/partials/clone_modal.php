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
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="with_git" value="1" id="cloneWithGit">
            <label class="form-check-label" for="cloneWithGit">Sertakan folder <code>.git</code> (tujuan jadi deployment Git juga)</label>
          </div>
          <div class="form-text">
            File di tujuan <strong>ditimpa jika namanya sama</strong>, tidak ada file tujuan yang dihapus.
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
  document.getElementById('cloneSrcLabel').textContent = label;
  var dest = document.getElementById('cloneDest');
  dest.value = '';
  Array.prototype.forEach.call(dest.options, function (o) { o.hidden = (o.value === srcKey); });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('cloneModal')).show();
};
</script>
