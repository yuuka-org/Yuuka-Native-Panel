<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('files.view');

$user = Auth::user();
$scope = (string) ($_GET['scope'] ?? $_POST['scope'] ?? '');
$name = (string) ($_GET['name'] ?? $_POST['name'] ?? '');

// Accessed straight from the sidebar (no scope/name yet) - show a picker
// instead of failing validation, so File Manager doesn't need a website/
// app row to be clicked first.
$isPicker = $scope === '' && $name === '' && $_SERVER['REQUEST_METHOD'] === 'GET';

if ($isPicker) {
    $websites = NginxService::listWebsites();
    $nodeApps = NodeService::listRegisteredApps();

    $pageTitle = 'File Manager';
    include __DIR__ . '/partials/header.php';
    ?>
    <h4 class="fw-bold mb-1">File Manager</h4>
    <p class="text-muted mb-4">Pilih website atau aplikasi Node.js yang mau dikelola filenya.</p>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="card stat-card">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-globe2 me-1"></i>Website PHP</div>
          <div class="list-group list-group-flush">
            <?php if (empty($websites)): ?>
              <div class="list-group-item text-muted">Belum ada website</div>
            <?php endif; ?>
            <?php foreach ($websites as $site): ?>
              <a class="list-group-item list-group-item-action" href="/file_manager.php?scope=website&name=<?= urlencode($site['domain']) ?>">
                <i class="bi bi-folder2-open me-1"></i><?= e($site['domain']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card stat-card">
          <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Node.js Apps</div>
          <div class="list-group list-group-flush">
            <?php if (empty($nodeApps)): ?>
              <div class="list-group-item text-muted">Belum ada aplikasi Node.js</div>
            <?php endif; ?>
            <?php foreach ($nodeApps as $app): ?>
              <a class="list-group-item list-group-item-action" href="/file_manager.php?scope=nodeapp&name=<?= urlencode($app['app_name']) ?>">
                <i class="bi bi-folder2-open me-1"></i><?= e($app['app_name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <h6 class="text-muted mt-4 mb-2">Jelajahi Semua (ala Explorer)</h6>
    <div class="row g-4">
      <div class="col-md-6">
        <a href="/file_manager.php?scope=www&name=root" class="card stat-card text-decoration-none text-body">
          <div class="card-body d-flex align-items-center gap-2">
            <i class="bi bi-hdd-network fs-4 text-primary"></i>
            <div>
              <div class="fw-semibold">Semua Website (/var/www)</div>
              <div class="text-muted small">Termasuk folder yang belum terdaftar sebagai website di panel</div>
            </div>
          </div>
        </a>
      </div>
      <div class="col-md-6">
        <a href="/file_manager.php?scope=nodeapps&name=root" class="card stat-card text-decoration-none text-body">
          <div class="card-body d-flex align-items-center gap-2">
            <i class="bi bi-hdd-network fs-4 text-primary"></i>
            <div>
              <div class="fw-semibold">Semua Aplikasi Node.js (/home/nodeapps/apps)</div>
              <div class="text-muted small">Termasuk folder yang belum terdaftar sebagai aplikasi di panel</div>
            </div>
          </div>
        </a>
      </div>
    </div>
    <?php
    include __DIR__ . '/partials/footer.php';
    exit;
}

try {
    FileManagerService::assertScope($scope, $name);
} catch (InvalidArgumentException $e) {
    flash('error', $e->getMessage());
    redirect($scope === 'nodeapp' ? '/nodejs.php' : '/websites.php');
}

$isRootScope = FileManagerService::isRootScope($scope);
if ($isRootScope) {
    $backUrl = '/file_manager.php';
    $scopeLabel = 'File Manager';
    $displayName = $scope === 'nodeapps' ? '/home/nodeapps/apps (semua aplikasi)' : '/var/www (semua website)';
} else {
    $backUrl = $scope === 'nodeapp' ? '/nodejs.php' : '/websites.php';
    $scopeLabel = $scope === 'nodeapp' ? 'Node.js Apps' : 'Website PHP';
    $displayName = $name;
}

// Raw file download - must happen before any HTML is emitted.
if (isset($_GET['download']) && $_GET['download'] !== '') {
    Rbac::require('files.view');
    $relPath = (string) $_GET['download'];
    try {
        $content = FileManagerService::readFile($scope, $name, $relPath);
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name));
    }
    $downloadName = basename($relPath);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = (string) ($_POST['action'] ?? '');
    $path = (string) ($_POST['path'] ?? '');

    try {
        if ($action === 'upload') {
            Rbac::require('files.manage');
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload file gagal atau tidak ada file dipilih');
            }
            $fileName = basename((string) $_FILES['file']['name']);
            if (!Validator::fileBaseName($fileName)) {
                throw new InvalidArgumentException('Nama file tidak valid');
            }
            $content = file_get_contents($_FILES['file']['tmp_name']);
            $target = $path !== '' ? $path . '/' . $fileName : $fileName;
            FileManagerService::writeFile($scope, $name, $target, (string) $content, $user['id']);
            flash('success', "File {$fileName} berhasil diupload.");
        } elseif ($action === 'upload_zip') {
            Rbac::require('files.manage');
            if (!isset($_FILES['zipfile']) || $_FILES['zipfile']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload ZIP gagal atau tidak ada file dipilih');
            }
            $zipBytes = file_get_contents($_FILES['zipfile']['tmp_name']);
            FileManagerService::extractZip($scope, $name, $path, (string) $zipBytes, $user['id']);
            flash('success', 'ZIP berhasil diekstrak.');
        } elseif ($action === 'mkdir') {
            Rbac::require('files.manage');
            $folderName = trim((string) ($_POST['folder_name'] ?? ''));
            if (!Validator::fileBaseName($folderName)) {
                throw new InvalidArgumentException('Nama folder tidak valid');
            }
            $target = $path !== '' ? $path . '/' . $folderName : $folderName;
            FileManagerService::mkdir($scope, $name, $target, $user['id']);
            flash('success', "Folder {$folderName} berhasil dibuat.");
        } elseif ($action === 'delete') {
            Rbac::require('files.manage');
            FileManagerService::delete($scope, $name, (string) ($_POST['target'] ?? ''), $user['id']);
            flash('success', 'Berhasil dihapus.');
        } elseif ($action === 'rename') {
            Rbac::require('files.manage');
            FileManagerService::rename(
                $scope,
                $name,
                (string) ($_POST['target'] ?? ''),
                trim((string) ($_POST['new_name'] ?? '')),
                $user['id']
            );
            flash('success', 'Berhasil diganti nama.');
        } elseif ($action === 'save_file') {
            Rbac::require('files.manage');
            $filePath = (string) ($_POST['file'] ?? '');
            FileManagerService::writeFile($scope, $name, $filePath, (string) ($_POST['content'] ?? ''), $user['id']);
            flash('success', 'File berhasil disimpan.');
            redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&edit=' . urlencode($filePath));
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
        // save_file has no 'path' field (edit view only tracks the file
        // itself) - on failure, send the user back to the file they were
        // editing instead of the generic redirect, which would otherwise
        // bounce them to the scope root and look like their edit vanished.
        if ($action === 'save_file' && (string) ($_POST['file'] ?? '') !== '') {
            redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&edit=' . urlencode((string) $_POST['file']));
        }
    }
    redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&path=' . urlencode($path));
}

$editFile = isset($_GET['edit']) ? (string) $_GET['edit'] : null;
$currentPath = (string) ($_GET['path'] ?? '');

$canManage = Rbac::can($user['role'], 'files.manage');
$extraHeadHtml = '';
$extraBodyHtml = '';

function fm_breadcrumbs(string $relPath): array
{
    if ($relPath === '') {
        return [];
    }
    $segments = explode('/', trim($relPath, '/'));
    $crumbs = [];
    $acc = '';
    foreach ($segments as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumbs[] = ['label' => $seg, 'path' => $acc];
    }
    return $crumbs;
}

/**
 * Bootstrap Icons ships a dedicated bi-filetype-* glyph for each of these
 * extensions (confirmed in the 1.11.x set already loaded via CDN in
 * header.php) - far more recognizable at a glance than the single generic
 * file icon every row used before. Falls back to a generic file/archive
 * icon for anything not in this list rather than guessing.
 */
function fm_file_icon(string $filename): string
{
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $aliases = ['yaml' => 'yml', 'jpeg' => 'jpg', 'htm' => 'html'];
    $ext = $aliases[$ext] ?? $ext;

    static $known = [
        'php', 'js', 'jsx', 'tsx', 'json', 'css', 'scss', 'sass', 'html', 'xml',
        'py', 'rb', 'java', 'cs', 'sh', 'sql', 'yml', 'md', 'mdx', 'txt', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'key',
        'jpg', 'png', 'gif', 'bmp', 'svg', 'psd', 'ai', 'raw', 'tiff', 'heic', 'heif',
        'mp3', 'wav', 'aac', 'm4p', 'mp4', 'mov',
        'ttf', 'otf', 'woff', 'exe',
    ];
    if (in_array($ext, $known, true)) {
        return "bi-filetype-{$ext}";
    }
    if (in_array($ext, ['zip', 'gz', 'tar', 'rar', '7z'], true)) {
        return 'bi-file-earmark-zip';
    }
    return 'bi-file-earmark-text';
}

/** Light color-coding by file category - purely visual, no logic depends on it. */
function fm_file_icon_color(string $filename): string
{
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    static $groups = [
        'text-info' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'psd', 'ai', 'raw', 'tiff', 'heic', 'heif'],
        'text-warning' => ['zip', 'gz', 'tar', 'rar', '7z'],
        'text-danger' => ['pdf'],
        'text-primary' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'key'],
        'text-success' => ['php', 'js', 'jsx', 'tsx', 'json', 'css', 'scss', 'sass', 'html', 'htm', 'xml', 'py', 'rb', 'java', 'cs', 'sh', 'sql', 'yml', 'yaml', 'md', 'mdx'],
        'text-secondary' => ['mp3', 'wav', 'aac', 'm4p', 'mp4', 'mov'],
    ];
    foreach ($groups as $color => $exts) {
        if (in_array($ext, $exts, true)) {
            return $color;
        }
    }
    return 'text-muted';
}

function fm_human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === end($units)) {
            return round($value, 1) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}

if ($editFile !== null) {
    try {
        $fileContent = FileManagerService::readFile($scope, $name, $editFile);
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name));
    }
    if (!FileManagerService::looksLikeText($fileContent)) {
        flash('error', 'File ini terdeteksi biner, tidak bisa diedit sebagai teks. Gunakan Download.');
        redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&path=' . urlencode(dirname($editFile) === '.' ? '' : dirname($editFile)));
    }

    $extraHeadHtml = '<link rel="stylesheet" href="/assets/vendor/codemirror/lib/codemirror.css">'
        . '<style>.CodeMirror{height:65vh;border:1px solid var(--bs-border-color);border-radius:.375rem;font-size:.875rem;}</style>';

    $extraBodyHtml = <<<'HTML'
<script src="/assets/vendor/codemirror/lib/codemirror.js"></script>
<script src="/assets/vendor/codemirror/mode/xml/xml.js"></script>
<script src="/assets/vendor/codemirror/mode/javascript/javascript.js"></script>
<script src="/assets/vendor/codemirror/mode/css/css.js"></script>
<script src="/assets/vendor/codemirror/mode/htmlmixed/htmlmixed.js"></script>
<script src="/assets/vendor/codemirror/mode/clike/clike.js"></script>
<script src="/assets/vendor/codemirror/mode/php/php.js"></script>
<script src="/assets/vendor/codemirror/mode/shell/shell.js"></script>
<script src="/assets/vendor/codemirror/mode/sql/sql.js"></script>
<script src="/assets/vendor/codemirror/mode/yaml/yaml.js"></script>
<script src="/assets/vendor/codemirror/mode/markdown/markdown.js"></script>
<script src="/assets/vendor/codemirror/mode/python/python.js"></script>
<script src="/assets/vendor/codemirror/mode/dockerfile/dockerfile.js"></script>
<script src="/assets/vendor/codemirror/addon/edit/matchbrackets.js"></script>
<script src="/assets/vendor/codemirror/addon/edit/closebrackets.js"></script>
<script>
(function () {
  var el = document.getElementById('editorArea');
  if (!el || typeof CodeMirror === 'undefined') { return; }
  var fileName = el.getAttribute('data-filename') || '';
  var base = fileName.toLowerCase();
  var ext = base.includes('.') ? base.split('.').pop() : '';
  var MODE_BY_EXT = {
    php: 'application/x-httpd-php', phtml: 'application/x-httpd-php',
    js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript',
    json: {name: 'javascript', json: true},
    css: 'css', scss: 'css', less: 'css',
    html: 'htmlmixed', htm: 'htmlmixed',
    xml: 'xml', svg: 'xml',
    sh: 'shell', bash: 'shell',
    sql: 'sql',
    yml: 'yaml', yaml: 'yaml',
    md: 'markdown', markdown: 'markdown',
    py: 'python'
  };
  var mode = MODE_BY_EXT[ext] || null;
  if (base === 'dockerfile') { mode = 'dockerfile'; }
  var cm = CodeMirror.fromTextArea(el, {
    mode: mode,
    lineNumbers: true,
    lineWrapping: true,
    matchBrackets: true,
    autoCloseBrackets: true,
    indentUnit: 4,
    readOnly: el.hasAttribute('readonly')
  });
  cm.on('change', function () { cm.save(); });
})();
</script>
HTML;
}

$pageTitle = 'File Manager';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="fw-bold mb-0">File Manager</h4>
    <p class="text-muted mb-0">
      <a href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left me-1"></i><?= e($scopeLabel) ?></a>
      &middot; <?= e($displayName) ?>
    </p>
  </div>
</div>

<?php if ($editFile !== null): ?>

  <div class="card stat-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><i class="bi <?= e(fm_file_icon($editFile)) ?> <?= e(fm_file_icon_color($editFile)) ?> me-1"></i><?= e($editFile) ?></span>
      <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode(dirname($editFile) === '.' ? '' : dirname($editFile)) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x-lg me-1"></i>Tutup
      </a>
    </div>
    <div class="card-body">
      <?php if ($canManage): ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="scope" value="<?= e($scope) ?>">
        <input type="hidden" name="name" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="save_file">
        <input type="hidden" name="file" value="<?= e($editFile) ?>">
        <textarea id="editorArea" name="content" data-filename="<?= e(basename($editFile)) ?>"><?= e($fileContent) ?></textarea>
        <div class="mt-3 d-flex justify-content-end gap-2">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
        </div>
      </form>
      <?php else: ?>
        <textarea id="editorArea" readonly data-filename="<?= e(basename($editFile)) ?>"><?= e($fileContent) ?></textarea>
        <p class="text-muted small mt-2 mb-0">Mode baca saja - kamu tidak memiliki izin untuk mengedit file.</p>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb bg-white border rounded px-3 py-2 mb-0">
      <li class="breadcrumb-item"><a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>"><i class="bi bi-hdd"></i> root</a></li>
      <?php foreach (fm_breadcrumbs($currentPath) as $i => $crumb): ?>
        <li class="breadcrumb-item"><a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($crumb['path']) ?>"><?= e($crumb['label']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <?php if ($canManage): ?>
  <div class="d-flex gap-2 mb-3">
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload me-1"></i>Upload File</button>
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadZipModal"><i class="bi bi-file-earmark-zip me-1"></i>Upload &amp; Extract ZIP</button>
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#mkdirModal"><i class="bi bi-folder-plus me-1"></i>Folder Baru</button>
  </div>
  <?php endif; ?>

  <?php
  try {
      $entries = FileManagerService::listDir($scope, $name, $currentPath);
  } catch (InvalidArgumentException|RuntimeException $e) {
      flash('error', $e->getMessage());
      $entries = [];
  }
  ?>

  <div class="card stat-card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr><th>Nama</th><th>Ukuran</th><th>Diubah</th><th class="text-end">Aksi</th></tr>
          </thead>
          <tbody>
          <?php if (empty($entries)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Folder ini kosong</td></tr>
          <?php endif; ?>
          <?php foreach ($entries as $entry):
              $entryRelPath = $currentPath !== '' ? $currentPath . '/' . $entry['name'] : $entry['name'];
              $isDir = $entry['type'] === 'dir';
              $isText = !$isDir && strlen($entry['name']) < 255;
          ?>
            <tr>
              <td>
                <?php if ($isDir): ?>
                  <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($entryRelPath) ?>">
                    <i class="bi bi-folder-fill text-warning me-1"></i><?= e($entry['name']) ?>
                  </a>
                <?php else: ?>
                  <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&edit=<?= urlencode($entryRelPath) ?>">
                    <i class="bi <?= e(fm_file_icon($entry['name'])) ?> <?= e(fm_file_icon_color($entry['name'])) ?> me-1"></i><?= e($entry['name']) ?>
                  </a>
                <?php endif; ?>
              </td>
              <td class="text-muted small"><?= $isDir ? '-' : e(fm_human_size($entry['size'])) ?></td>
              <td class="text-muted small"><?= e(date('Y-m-d H:i', $entry['mtime'])) ?></td>
              <td class="text-end text-nowrap">
                <?php if (!$isDir): ?>
                <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&download=<?= urlencode($entryRelPath) ?>" class="btn btn-sm btn-outline-secondary" title="Download"><i class="bi bi-download"></i></a>
                <?php endif; ?>
                <?php if ($canManage): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" title="Rename" data-bs-toggle="modal" data-bs-target="#renameModal" data-target="<?= e($entryRelPath) ?>" data-current-name="<?= e($entry['name']) ?>"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus" data-bs-toggle="modal" data-bs-target="#deleteModal" data-target="<?= e($entryRelPath) ?>" data-label="<?= e($entry['name']) ?>"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

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
            <p>Yakin ingin menghapus <strong id="deleteLabel"></strong>? Tindakan ini tidak dapat dibatalkan.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-danger">Hapus</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
  document.getElementById('renameModal').addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    document.getElementById('renameTarget').value = btn.getAttribute('data-target');
    document.getElementById('renameNewName').value = btn.getAttribute('data-current-name');
  });
  document.getElementById('deleteModal').addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    document.getElementById('deleteTarget').value = btn.getAttribute('data-target');
    document.getElementById('deleteLabel').textContent = btn.getAttribute('data-label');
  });
  </script>
  <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
