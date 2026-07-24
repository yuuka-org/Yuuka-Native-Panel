<?php
declare(strict_types=1);
/**
 * Rendered both as part of the normal file_manager.php page (wrapped in
 * #fmBrowseRoot) AND standalone as an AJAX fragment response (see the
 * $isAjaxBrowse branch in file_manager.php) - clicking a folder/breadcrumb
 * link fetches this same partial over fetch() and swaps it into
 * #fmBrowseRoot's innerHTML instead of a full page navigation, which is
 * what keeps the address bar from ever showing ?scope=...&path=... while
 * browsing. Never include a <script> tag in here: scripts injected via
 * innerHTML don't execute, and the JS driving this page (checkbox/bulk
 * toolbar/context menu/drag-drop) is delegation-based from document level
 * specifically so it keeps working no matter how many times this partial
 * gets swapped in - see the shared script in file_manager.php's
 * $extraBodyHtml.
 *
 * Expects $scope, $name, $currentPath, $showHidden, $backUrl, $canManage,
 * $entries, $clipboard, $clipboardFamilyMatches already set by the caller.
 */
?>
<?php include __DIR__ . '/flash.php'; ?>

<style>
  .fm-btn-xs{padding:.2rem .55rem;font-size:.75rem;}
  .fm-path-bar,.fm-path-bar .btn,.fm-path-bar .form-control,.fm-path-bar .breadcrumb{height:calc(1.5em + .5rem + 2px);padding:.25rem .6rem;font-size:.875rem;line-height:1.5;}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3 fm-path-bar">
  <a href="<?= e($backUrl) ?>" class="btn btn-outline-secondary" title="Kembali"><i class="bi bi-arrow-left"></i></a>

  <nav aria-label="breadcrumb" class="mb-0 flex-grow-1">
    <ol class="breadcrumb bg-body-tertiary border rounded mb-0 w-100 align-items-center">
      <li class="breadcrumb-item"><a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>" class="fm-nav-link"><i class="bi bi-hdd"></i> root</a></li>
      <?php foreach (fm_breadcrumbs($currentPath) as $i => $crumb): ?>
        <li class="breadcrumb-item"><a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($crumb['path']) ?>" class="fm-nav-link"><?= e($crumb['label']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <form method="get" class="d-flex gap-1" id="fmSearchForm" style="max-width:260px">
    <input type="hidden" name="scope" value="<?= e($scope) ?>">
    <input type="hidden" name="name" value="<?= e($name) ?>">
    <input type="text" name="search" class="form-control" placeholder="Cari nama file...">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
</div>

<form method="post" id="bulkForm">
  <?= Csrf::field() ?>
  <input type="hidden" name="scope" value="<?= e($scope) ?>">
  <input type="hidden" name="name" value="<?= e($name) ?>">
  <input type="hidden" name="path" value="<?= e($currentPath) ?>">
  <input type="hidden" name="action" id="bulkAction" value="">
  <input type="hidden" name="mode" id="bulkChmodMode" value="">

  <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <?php if ($canManage): ?>
    <button type="button" class="btn btn-primary btn-sm fm-btn-xs" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload me-1"></i>Upload File</button>
    <button type="button" class="btn btn-outline-primary btn-sm fm-btn-xs" data-bs-toggle="modal" data-bs-target="#uploadZipModal"><i class="bi bi-file-earmark-zip me-1"></i>Upload &amp; Extract ZIP</button>
    <button type="button" class="btn btn-outline-secondary btn-sm fm-btn-xs" data-bs-toggle="modal" data-bs-target="#mkdirModal"><i class="bi bi-folder-plus me-1"></i>Folder Baru</button>
    <button type="button" class="btn btn-outline-secondary btn-sm fm-btn-xs" data-bs-toggle="modal" data-bs-target="#newFileModal"><i class="bi bi-file-earmark-plus me-1"></i>File Baru</button>
    <?php endif; ?>
    <a href="?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($currentPath) ?>&show_hidden=<?= $showHidden ? '0' : '1' ?>" class="btn btn-outline-secondary btn-sm fm-btn-xs fm-nav-link">
      <i class="bi bi-eye<?= $showHidden ? '-slash' : '' ?> me-1"></i><?= $showHidden ? 'Sembunyikan' : 'Tampilkan' ?> File Tersembunyi
    </a>
    <a href="?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&trash=1" class="btn btn-outline-secondary btn-sm fm-btn-xs"><i class="bi bi-trash3 me-1"></i>Recycle Bin</a>

    <?php if ($canManage): ?>
    <div class="d-none align-items-center gap-2 ms-auto p-1 bg-body-tertiary rounded" id="bulkToolbar">
      <span class="small text-muted" id="bulkCount"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary fm-btn-xs" onclick="fmSetBulkAction('copy_to_clipboard')"><i class="bi bi-clipboard me-1"></i>Salin</button>
      <button type="button" class="btn btn-sm btn-outline-secondary fm-btn-xs" onclick="fmSetBulkAction('cut_to_clipboard')"><i class="bi bi-scissors me-1"></i>Potong</button>
      <button type="button" class="btn btn-sm btn-outline-secondary fm-btn-xs" data-bs-toggle="modal" data-bs-target="#chmodModal"><i class="bi bi-shield-lock me-1"></i>Ubah Izin</button>
      <button type="button" class="btn btn-sm btn-outline-danger fm-btn-xs" onclick="fmConfirmBulkDelete()"><i class="bi bi-trash me-1"></i>Hapus</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($canManage && is_array($clipboard)): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center py-2 mb-3">
    <span><i class="bi bi-clipboard me-1"></i><?= count($clipboard['items']) ?> item (<?= $clipboard['mode'] === 'cut' ? 'Potong' : 'Salin' ?>) dari <strong><?= e((string) $clipboard['name']) ?></strong>
      <?php if (!$clipboardFamilyMatches): ?><span class="text-muted">- pindah ke tampilan Website/Node.js yang sesuai untuk tempel</span><?php endif; ?>
    </span>
    <span class="d-flex gap-2">
      <?php if ($clipboardFamilyMatches): ?>
      <button type="button" class="btn btn-sm btn-primary" onclick="fmSetBulkAction('paste_clipboard')">Tempel di Sini</button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fmSetBulkAction('clear_clipboard')">Batal</button>
    </span>
  </div>
  <?php endif; ?>

  <div class="card stat-card" id="fmDropZone">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <?php if ($canManage): ?><th style="width:36px"><input type="checkbox" id="selectAll"></th><?php endif; ?>
              <th>Nama</th><th>Ukuran</th><th>Izin</th><th>Diubah</th><th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($entries)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Folder ini kosong - atau seret &amp; lepas file di sini untuk upload</td></tr>
          <?php endif; ?>
          <?php foreach ($entries as $entry):
              $entryRelPath = $currentPath !== '' ? $currentPath . '/' . $entry['name'] : $entry['name'];
              $isDir = $entry['type'] === 'dir';
          ?>
            <tr class="fm-row" data-fm-relpath="<?= e($entryRelPath) ?>" data-fm-name="<?= e($entry['name']) ?>" data-fm-is-dir="<?= $isDir ? '1' : '0' ?>">
              <?php if ($canManage): ?>
              <td><input type="checkbox" name="targets[]" value="<?= e($entryRelPath) ?>" class="fm-check"></td>
              <?php endif; ?>
              <td>
                <?php if ($isDir): ?>
                  <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($entryRelPath) ?>" class="fm-nav-link">
                    <i class="bi bi-folder-fill text-warning me-1"></i><?= e($entry['name']) ?>
                  </a>
                <?php else: ?>
                  <a href="#" data-fm-open-file="<?= e($entryRelPath) ?>">
                    <i class="bi <?= e(fm_file_icon($entry['name'])) ?> <?= e(fm_file_icon_color($entry['name'])) ?> me-1"></i><?= e($entry['name']) ?>
                  </a>
                <?php endif; ?>
              </td>
              <td class="text-muted small"><?= $isDir ? '-' : e(fm_human_size($entry['size'])) ?></td>
              <td class="text-muted small"><code><?= e($entry['mode']) ?></code></td>
              <td class="text-muted small"><?= e(date('Y-m-d H:i', $entry['mtime'])) ?></td>
              <td class="text-end text-nowrap">
                <?php if (!$isDir): ?>
                <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&download=<?= urlencode($entryRelPath) ?>" class="btn btn-sm btn-outline-secondary" title="Download"><i class="bi bi-download"></i></a>
                <?php endif; ?>
                <?php if ($canManage): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" title="Ubah Izin" data-bs-toggle="modal" data-bs-target="#chmodModal" data-target="<?= e($entryRelPath) ?>" data-label="<?= e($entry['name']) ?>"><i class="bi bi-shield-lock"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" title="Rename" data-bs-toggle="modal" data-bs-target="#renameModal" data-target="<?= e($entryRelPath) ?>" data-current-name="<?= e($entry['name']) ?>"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus (ke Recycle Bin)" data-bs-toggle="modal" data-bs-target="#deleteModal" data-target="<?= e($entryRelPath) ?>" data-label="<?= e($entry['name']) ?>"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</form>

<?php if ($canManage): ?>
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Upload File</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="path" value="<?= e($currentPath) ?>">
          <p class="text-muted small">Diupload ke: <code>/<?= e($currentPath) ?></code></p>
          <input type="file" name="file" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="uploadZipModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Upload &amp; Extract ZIP</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="upload_zip">
          <input type="hidden" name="path" value="<?= e($currentPath) ?>">
          <p class="text-muted small">Diekstrak ke: <code>/<?= e($currentPath) ?></code> (file dengan nama sama akan ditimpa)</p>
          <input type="file" name="zipfile" accept=".zip" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Upload &amp; Extract</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="mkdirModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Folder Baru</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="mkdir">
          <input type="hidden" name="path" value="<?= e($currentPath) ?>">
          <label class="form-label">Nama Folder</label>
          <input type="text" name="folder_name" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Buat</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="newFileModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">File Baru</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="new_file">
          <input type="hidden" name="path" value="<?= e($currentPath) ?>">
          <label class="form-label">Nama File</label>
          <input type="text" name="file_name" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Buat</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="renameModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Ganti Nama</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="path" value="<?= e($currentPath) ?>">
          <input type="hidden" name="target" id="renameTarget">
          <label class="form-label">Nama Baru</label>
          <input type="text" name="new_name" id="renameNewName" class="form-control" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Hapus</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="path" value="<?= e($currentPath) ?>">
          <input type="hidden" name="target" id="deleteTarget">
          <p>Pindahkan <strong id="deleteLabel"></strong> ke Recycle Bin? Bisa dipulihkan lagi lewat menu Recycle Bin.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger">Hapus</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="chmodModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Ubah Izin</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="text-muted small" id="chmodTargetLabel"></p>
        <label class="form-label">Mode (oktal, 3 digit)</label>
        <input type="text" id="chmodModeInput" class="form-control mb-2" pattern="^[0-7][0-7][0-7]$" placeholder="755" maxlength="3" required>
        <div class="form-text mb-2">Digit terakhir ('other'/dunia) tidak boleh punya izin tulis - mode seperti 777/776/773 ditolak.</div>
        <div class="d-flex flex-wrap gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('chmodModeInput').value='755'">755 (folder)</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('chmodModeInput').value='644'">644 (file)</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('chmodModeInput').value='750'">750</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('chmodModeInput').value='640'">640</button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="fmSubmitChmod()">Terapkan</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="dropdown-menu" id="fmContextMenu" style="display:none; position:fixed;">
  <button type="button" class="dropdown-item" data-fm-ctx="open"><i class="bi bi-box-arrow-up-right me-2"></i>Buka</button>
  <button type="button" class="dropdown-item" data-fm-ctx="download"><i class="bi bi-download me-2"></i>Download</button>
  <?php if ($canManage): ?>
  <button type="button" class="dropdown-item" data-fm-ctx="copy"><i class="bi bi-clipboard me-2"></i>Salin</button>
  <button type="button" class="dropdown-item" data-fm-ctx="cut"><i class="bi bi-scissors me-2"></i>Potong</button>
  <button type="button" class="dropdown-item" data-fm-ctx="chmod"><i class="bi bi-shield-lock me-2"></i>Ubah Izin</button>
  <button type="button" class="dropdown-item" data-fm-ctx="rename"><i class="bi bi-pencil me-2"></i>Rename</button>
  <?php endif; ?>
  <?php if ($canTerminal): ?>
  <button type="button" class="dropdown-item" data-fm-ctx="terminal"><i class="bi bi-terminal me-2"></i>Open in Terminal</button>
  <?php endif; ?>
  <?php if ($canManage): ?>
  <div class="dropdown-divider"></div>
  <button type="button" class="dropdown-item text-danger" data-fm-ctx="delete"><i class="bi bi-trash me-2"></i>Hapus</button>
  <?php endif; ?>
</div>
