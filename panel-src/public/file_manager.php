<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('files.view');

const FM_BULK_MAX_ITEMS = 100;

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

/** website/www share ownership (www-data), nodeapp/nodeapps share ownership (nodeapps) - mirrors panel-exec.sh's fm_scope_family exactly. */
function fm_scope_family(string $scope): string
{
    return in_array($scope, ['website', 'www'], true) ? 'website' : 'nodeapp';
}

function fm_flash_bulk_result(int $ok, array $failures, string $verb): void
{
    if (empty($failures)) {
        flash('success', "{$ok} item berhasil {$verb}.");
        return;
    }
    $shown = array_slice($failures, 0, 10);
    $more = count($failures) - count($shown);
    $msg = "{$ok} berhasil, " . count($failures) . " gagal: " . implode('; ', $shown);
    if ($more > 0) {
        $msg .= " (+{$more} lainnya)";
    }
    flash('error', $msg);
}

/** @param string[] $targets */
function fm_assert_bulk_count(array $targets): array
{
    $targets = array_values(array_unique(array_map('strval', $targets)));
    if (empty($targets)) {
        throw new InvalidArgumentException('Tidak ada item dipilih');
    }
    if (count($targets) > FM_BULK_MAX_ITEMS) {
        throw new InvalidArgumentException('Maksimal ' . FM_BULK_MAX_ITEMS . ' item per aksi massal (dipilih: ' . count($targets) . ')');
    }
    return $targets;
}

function fm_bulk_delete(string $scope, string $name, array $targets, ?int $userId): void
{
    $targets = fm_assert_bulk_count($targets);
    set_time_limit(120);
    $ok = 0;
    $failures = [];
    foreach ($targets as $target) {
        try {
            FileManagerService::delete($scope, $name, $target, $userId);
            $ok++;
        } catch (InvalidArgumentException|RuntimeException $e) {
            $failures[] = basename($target) . ': ' . $e->getMessage();
        }
    }
    fm_flash_bulk_result($ok, $failures, 'dipindahkan ke Recycle Bin');
}

function fm_bulk_chmod(string $scope, string $name, array $targets, string $mode, ?int $userId): void
{
    $targets = fm_assert_bulk_count($targets);
    if (!Validator::chmodMode($mode)) {
        throw new InvalidArgumentException('Mode izin tidak valid');
    }
    set_time_limit(120);
    $ok = 0;
    $failures = [];
    foreach ($targets as $target) {
        try {
            FileManagerService::chmod($scope, $name, $target, $mode, $userId);
            $ok++;
        } catch (InvalidArgumentException|RuntimeException $e) {
            $failures[] = basename($target) . ': ' . $e->getMessage();
        }
    }
    fm_flash_bulk_result($ok, $failures, 'diubah izinnya');
}

/** @throws InvalidArgumentException|RuntimeException */
function fm_do_upload(string $scope, string $name, string $path, ?int $userId): string
{
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload file gagal atau tidak ada file dipilih');
    }
    $fileName = basename((string) $_FILES['file']['name']);
    if (!Validator::fileBaseName($fileName)) {
        throw new InvalidArgumentException('Nama file tidak valid');
    }
    $content = file_get_contents($_FILES['file']['tmp_name']);
    $target = $path !== '' ? $path . '/' . $fileName : $fileName;
    FileManagerService::writeFile($scope, $name, $target, (string) $content, $userId);
    return $fileName;
}

function fm_paste_clipboard(string $destScope, string $destName, string $destPath, ?int $userId): void
{
    $clipboard = $_SESSION['fm_clipboard'] ?? null;
    if (!is_array($clipboard) || empty($clipboard['items'])) {
        throw new InvalidArgumentException('Clipboard kosong');
    }
    $srcScope = (string) $clipboard['scope'];
    $srcName = (string) $clipboard['name'];
    $mode = (string) $clipboard['mode'];
    $items = fm_assert_bulk_count((array) $clipboard['items']);

    set_time_limit(120);
    $ok = 0;
    $failures = [];
    foreach ($items as $item) {
        $destRelPath = $destPath !== '' ? $destPath . '/' . basename($item) : basename($item);
        try {
            if ($mode === 'cut') {
                FileManagerService::move($srcScope, $srcName, $item, $destScope, $destName, $destRelPath, $userId);
            } else {
                FileManagerService::copy($srcScope, $srcName, $item, $destScope, $destName, $destRelPath, $userId);
            }
            $ok++;
        } catch (InvalidArgumentException|RuntimeException $e) {
            $failures[] = basename($item) . ': ' . $e->getMessage();
        }
    }
    fm_flash_bulk_result($ok, $failures, $mode === 'cut' ? 'dipindahkan' : 'disalin');
    if ($mode === 'cut' && $ok > 0) {
        unset($_SESSION['fm_clipboard']);
    }
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

$canManage = Rbac::can($user['role'], 'files.manage');
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
    $action = (string) ($_POST['action'] ?? '');
    $path = (string) ($_POST['path'] ?? '');

    // Drag & drop upload: same validation/writeFile() path as the normal
    // upload modal, just returns JSON instead of flash()+redirect() so
    // the client can fire several of these via fetch() without a full
    // page reload per file.
    if ($action === 'upload' && $isAjax) {
        try {
            Rbac::require('files.manage');
            $fileName = fm_do_upload($scope, $name, $path, $user['id']);
            jsonResponse(['ok' => true, 'name' => $fileName]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    try {
        if ($action === 'upload') {
            Rbac::require('files.manage');
            $fileName = fm_do_upload($scope, $name, $path, $user['id']);
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
        } elseif ($action === 'new_file') {
            Rbac::require('files.manage');
            $fileName = trim((string) ($_POST['file_name'] ?? ''));
            if (!Validator::fileBaseName($fileName)) {
                throw new InvalidArgumentException('Nama file tidak valid');
            }
            $target = $path !== '' ? $path . '/' . $fileName : $fileName;
            FileManagerService::writeFile($scope, $name, $target, '', $user['id']);
            flash('success', "File {$fileName} berhasil dibuat.");
        } elseif ($action === 'delete') {
            Rbac::require('files.manage');
            FileManagerService::delete($scope, $name, (string) ($_POST['target'] ?? ''), $user['id']);
            flash('success', 'Dipindahkan ke Recycle Bin.');
        } elseif ($action === 'bulk_delete') {
            Rbac::require('files.manage');
            fm_bulk_delete($scope, $name, (array) ($_POST['targets'] ?? []), $user['id']);
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
        } elseif ($action === 'chmod') {
            Rbac::require('files.manage');
            fm_bulk_chmod($scope, $name, (array) ($_POST['targets'] ?? []), (string) ($_POST['mode'] ?? ''), $user['id']);
        } elseif ($action === 'copy_to_clipboard' || $action === 'cut_to_clipboard') {
            Rbac::require('files.manage');
            $targets = fm_assert_bulk_count((array) ($_POST['targets'] ?? []));
            $_SESSION['fm_clipboard'] = [
                'mode' => $action === 'cut_to_clipboard' ? 'cut' : 'copy',
                'scope' => $scope,
                'name' => $name,
                'items' => $targets,
            ];
            flash('success', count($targets) . ' item disalin ke clipboard.');
        } elseif ($action === 'clear_clipboard') {
            unset($_SESSION['fm_clipboard']);
        } elseif ($action === 'paste_clipboard') {
            Rbac::require('files.manage');
            fm_paste_clipboard($scope, $name, $path, $user['id']);
        } elseif ($action === 'trash_restore') {
            Rbac::require('files.manage');
            FileManagerService::trashRestore($scope, $name, (string) ($_POST['trash_entry'] ?? ''), $user['id']);
            flash('success', 'Berhasil dipulihkan.');
        } elseif ($action === 'trash_delete') {
            Rbac::require('files.manage');
            FileManagerService::trashDelete($scope, $name, (string) ($_POST['trash_entry'] ?? ''), $user['id']);
            flash('success', 'Dihapus permanen.');
        } elseif ($action === 'trash_empty') {
            Rbac::require('files.manage');
            FileManagerService::trashEmpty($scope, $name, $user['id']);
            flash('success', 'Recycle Bin dikosongkan.');
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

    if (in_array($action, ['trash_restore', 'trash_delete', 'trash_empty'], true)) {
        redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&trash=1');
    }
    redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&path=' . urlencode($path));
}

$editFile = isset($_GET['edit']) ? (string) $_GET['edit'] : null;
$currentPath = (string) ($_GET['path'] ?? '');
$showHidden = ($_GET['show_hidden'] ?? '') === '1';
$searchQuery = trim((string) ($_GET['search'] ?? ''));
$showTrash = ($_GET['trash'] ?? '') === '1';

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

<?php elseif ($showTrash):

  try {
      $trashEntries = FileManagerService::trashList($scope, $name);
  } catch (InvalidArgumentException|RuntimeException $e) {
      flash('error', $e->getMessage());
      $trashEntries = [];
  }
  ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-trash3 me-1"></i>Recycle Bin: <?= count($trashEntries) ?> item</h6>
    <div class="d-flex gap-2">
      <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali ke Folder</a>
      <?php if ($canManage && !empty($trashEntries)): ?>
      <form method="post" data-confirm="Kosongkan Recycle Bin? Semua item di dalamnya akan dihapus PERMANEN dan tidak bisa dipulihkan.">
        <?= Csrf::field() ?>
        <input type="hidden" name="scope" value="<?= e($scope) ?>">
        <input type="hidden" name="name" value="<?= e($name) ?>">
        <input type="hidden" name="action" value="trash_empty">
        <button class="btn btn-sm btn-danger"><i class="bi bi-trash3 me-1"></i>Kosongkan Recycle Bin</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card stat-card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th>Nama</th><th>Lokasi Asal</th><th>Ukuran</th><th>Dihapus</th><th class="text-end">Aksi</th></tr></thead>
          <tbody>
          <?php if (empty($trashEntries)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Recycle Bin kosong</td></tr>
          <?php endif; ?>
          <?php foreach ($trashEntries as $t): $isDir = $t['type'] === 'dir'; $origName = basename($t['origPath']) !== '' ? basename($t['origPath']) : $t['name']; ?>
            <tr>
              <td>
                <?php if ($isDir): ?>
                  <i class="bi bi-folder-fill text-warning me-1"></i><?= e($origName) ?>
                <?php else: ?>
                  <i class="bi <?= e(fm_file_icon($origName)) ?> <?= e(fm_file_icon_color($origName)) ?> me-1"></i><?= e($origName) ?>
                <?php endif; ?>
              </td>
              <td class="text-muted small">/<?= e($t['origPath']) ?></td>
              <td class="text-muted small"><?= $isDir ? '-' : e(fm_human_size($t['size'])) ?></td>
              <td class="text-muted small"><?= e(date('Y-m-d H:i', $t['mtime'])) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($canManage): ?>
                <form method="post" class="d-inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="scope" value="<?= e($scope) ?>">
                  <input type="hidden" name="name" value="<?= e($name) ?>">
                  <input type="hidden" name="action" value="trash_restore">
                  <input type="hidden" name="trash_entry" value="<?= e($t['name']) ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="Pulihkan"><i class="bi bi-arrow-counterclockwise"></i></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Hapus permanen <?= e($origName) ?>? Tidak bisa dibatalkan.">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="scope" value="<?= e($scope) ?>">
                  <input type="hidden" name="name" value="<?= e($name) ?>">
                  <input type="hidden" name="action" value="trash_delete">
                  <input type="hidden" name="trash_entry" value="<?= e($t['name']) ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Hapus Permanen"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($searchQuery !== ''):

  try {
      $searchResults = FileManagerService::search($scope, $name, $searchQuery);
  } catch (InvalidArgumentException|RuntimeException $e) {
      flash('error', $e->getMessage());
      $searchResults = [];
  }
  ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0">Hasil pencarian "<?= e($searchQuery) ?>": <?= count($searchResults) ?> item</h6>
    <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali ke Folder</a>
  </div>

  <div class="card stat-card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th>Nama</th><th>Lokasi</th><th>Ukuran</th><th>Diubah</th></tr></thead>
          <tbody>
          <?php if (empty($searchResults)): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada hasil</td></tr>
          <?php endif; ?>
          <?php foreach ($searchResults as $r): $isDir = $r['type'] === 'dir'; $parentPath = dirname($r['relPath']); $parentPath = $parentPath === '.' ? '' : $parentPath; ?>
            <tr>
              <td>
                <?php if ($isDir): ?>
                  <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($r['relPath']) ?>"><i class="bi bi-folder-fill text-warning me-1"></i><?= e($r['name']) ?></a>
                <?php else: ?>
                  <a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&edit=<?= urlencode($r['relPath']) ?>"><i class="bi <?= e(fm_file_icon($r['name'])) ?> <?= e(fm_file_icon_color($r['name'])) ?> me-1"></i><?= e($r['name']) ?></a>
                <?php endif; ?>
              </td>
              <td class="text-muted small">/<?= e($parentPath) ?></td>
              <td class="text-muted small"><?= $isDir ? '-' : e(fm_human_size($r['size'])) ?></td>
              <td class="text-muted small"><?= e(date('Y-m-d H:i', $r['mtime'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php else: ?>

  <?php
  try {
      $entries = FileManagerService::listDir($scope, $name, $currentPath);
  } catch (InvalidArgumentException|RuntimeException $e) {
      flash('error', $e->getMessage());
      $entries = [];
  }
  if (!$showHidden) {
      $entries = array_values(array_filter($entries, static fn(array $e): bool => !str_starts_with($e['name'], '.')));
  }

  $clipboard = $_SESSION['fm_clipboard'] ?? null;
  $clipboardFamilyMatches = is_array($clipboard) && fm_scope_family((string) $clipboard['scope']) === fm_scope_family($scope);
  ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <nav aria-label="breadcrumb" class="mb-0">
      <ol class="breadcrumb bg-white border rounded px-3 py-2 mb-0">
        <li class="breadcrumb-item"><a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>"><i class="bi bi-hdd"></i> root</a></li>
        <?php foreach (fm_breadcrumbs($currentPath) as $i => $crumb): ?>
          <li class="breadcrumb-item"><a href="/file_manager.php?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($crumb['path']) ?>"><?= e($crumb['label']) ?></a></li>
        <?php endforeach; ?>
      </ol>
    </nav>

    <form method="get" class="d-flex gap-1" id="fmSearchForm" style="max-width:260px">
      <input type="hidden" name="scope" value="<?= e($scope) ?>">
      <input type="hidden" name="name" value="<?= e($name) ?>">
      <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari nama file...">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button>
    </form>

    <?php if ($canManage): ?>
    <div class="d-none align-items-center gap-2 p-2 bg-body-tertiary rounded" id="bulkToolbar">
      <span class="small text-muted" id="bulkCount"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fmSetBulkAction('copy_to_clipboard')"><i class="bi bi-clipboard me-1"></i>Salin</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fmSetBulkAction('cut_to_clipboard')"><i class="bi bi-scissors me-1"></i>Potong</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#chmodModal"><i class="bi bi-shield-lock me-1"></i>Ubah Izin</button>
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="fmConfirmBulkDelete()"><i class="bi bi-trash me-1"></i>Hapus</button>
    </div>
    <?php endif; ?>
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
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload me-1"></i>Upload File</button>
      <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadZipModal"><i class="bi bi-file-earmark-zip me-1"></i>Upload &amp; Extract ZIP</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#mkdirModal"><i class="bi bi-folder-plus me-1"></i>Folder Baru</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#newFileModal"><i class="bi bi-file-earmark-plus me-1"></i>File Baru</button>
      <?php endif; ?>
      <a href="?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&path=<?= urlencode($currentPath) ?>&show_hidden=<?= $showHidden ? '0' : '1' ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-eye<?= $showHidden ? '-slash' : '' ?> me-1"></i><?= $showHidden ? 'Sembunyikan' : 'Tampilkan' ?> File Tersembunyi
      </a>
      <a href="?scope=<?= urlencode($scope) ?>&name=<?= urlencode($name) ?>&trash=1" class="btn btn-outline-secondary btn-sm"><i class="bi bi-trash3 me-1"></i>Recycle Bin</a>
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
              <tr>
                <?php if ($canManage): ?>
                <td><input type="checkbox" name="targets[]" value="<?= e($entryRelPath) ?>" class="fm-check"></td>
                <?php endif; ?>
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
  document.getElementById('chmodModal').addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    var singleTarget = btn ? btn.getAttribute('data-target') : null;
    var label = document.getElementById('chmodTargetLabel');
    if (singleTarget) {
      document.querySelectorAll('.fm-check').forEach(function (c) { c.checked = (c.value === singleTarget); });
      label.textContent = 'Untuk: ' + btn.getAttribute('data-label');
    } else {
      var n = document.querySelectorAll('.fm-check:checked').length;
      label.textContent = n + ' item dipilih';
    }
    document.getElementById('chmodModeInput').value = '';
  });

  (function () {
    var selectAll = document.getElementById('selectAll');
    var checks = document.querySelectorAll('.fm-check');
    var toolbar = document.getElementById('bulkToolbar');
    var countLabel = document.getElementById('bulkCount');
    var searchForm = document.getElementById('fmSearchForm');

    function updateToolbar() {
      if (!toolbar) return;
      var checked = document.querySelectorAll('.fm-check:checked');
      if (checked.length > 0) {
        toolbar.classList.remove('d-none');
        toolbar.classList.add('d-flex');
        countLabel.textContent = checked.length + ' dipilih';
        if (searchForm) searchForm.classList.add('d-none');
      } else {
        toolbar.classList.add('d-none');
        toolbar.classList.remove('d-flex');
        if (searchForm) searchForm.classList.remove('d-none');
      }
    }
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = selectAll.checked; });
        updateToolbar();
      });
    }
    checks.forEach(function (c) { c.addEventListener('change', updateToolbar); });

    window.fmSetBulkAction = function (action) {
      document.getElementById('bulkAction').value = action;
      document.getElementById('bulkForm').submit();
    };
    window.fmConfirmBulkDelete = function () {
      var checked = document.querySelectorAll('.fm-check:checked').length;
      if (checked === 0) return;
      if (!confirm('Pindahkan ' + checked + ' item ke Recycle Bin?')) return;
      fmSetBulkAction('bulk_delete');
    };
    window.fmSubmitChmod = function () {
      var modeVal = document.getElementById('chmodModeInput').value;
      if (!/^[0-7][0-7][0-7]$/.test(modeVal)) {
        alert('Mode tidak valid - harus 3 digit oktal, contoh 755 atau 644.');
        return;
      }
      document.getElementById('bulkChmodMode').value = modeVal;
      fmSetBulkAction('chmod');
    };

    // Drag & drop upload - reuses the same 'upload' POST action as the
    // Upload File modal, just marked as an AJAX request (X-Requested-With)
    // so the server returns JSON instead of flash()+redirect(), letting
    // several dropped files upload via sequential fetch() calls without a
    // full page reload per file.
    var dropZone = document.getElementById('fmDropZone');
    if (dropZone) {
      ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
          e.preventDefault(); e.stopPropagation();
          dropZone.classList.add('border-primary');
        });
      });
      ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
          e.preventDefault(); e.stopPropagation();
          dropZone.classList.remove('border-primary');
        });
      });
      dropZone.addEventListener('drop', function (e) {
        var files = e.dataTransfer ? e.dataTransfer.files : null;
        if (!files || files.length === 0) return;
        fmUploadDroppedFiles(files);
      });
    }

    function fmUploadDroppedFiles(files) {
      var i = 0;
      function next() {
        if (i >= files.length) { location.reload(); return; }
        var file = files[i++];
        var fd = new FormData();
        fd.append('_csrf', <?= json_encode(Csrf::token()) ?>);
        fd.append('scope', <?= json_encode($scope) ?>);
        fd.append('name', <?= json_encode($name) ?>);
        fd.append('action', 'upload');
        fd.append('path', <?= json_encode($currentPath) ?>);
        fd.append('file', file);
        fetch(window.location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function () { next(); })
          .catch(function () { next(); });
      }
      next();
    }
  })();
  </script>
  <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
