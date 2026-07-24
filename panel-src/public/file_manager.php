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

$backUrl = FileManagerService::isRootScope($scope)
    ? '/file_manager.php'
    : ($scope === 'nodeapp' ? '/nodejs.php' : '/websites.php');

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

// Editor popup content fetch - the editor never navigates to its own page,
// it opens over fetch() from wherever the user currently is (browse/search
// results), so this always answers in JSON.
if (isset($_GET['ajax_edit']) && $_GET['ajax_edit'] !== '') {
    Rbac::require('files.view');
    $ajaxEditPath = (string) $_GET['ajax_edit'];
    try {
        $ajaxEditContent = FileManagerService::readFile($scope, $name, $ajaxEditPath);
        if (!FileManagerService::looksLikeText($ajaxEditContent)) {
            jsonResponse(['ok' => false, 'error' => 'File ini terdeteksi biner, tidak bisa diedit sebagai teks. Gunakan Download.'], 400);
        }
        jsonResponse(['ok' => true, 'name' => basename($ajaxEditPath), 'relPath' => $ajaxEditPath, 'content' => $ajaxEditContent]);
    } catch (InvalidArgumentException|RuntimeException $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

$canManage = Rbac::can($user['role'], 'files.manage');
$canTerminal = Rbac::can($user['role'], 'terminal.access');
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

    // Editor popup save: content never touches PHP-rendered HTML (the
    // editor loads/saves purely over fetch()), so this always answers in
    // JSON regardless of $isAjax - there is no non-AJAX caller for it.
    if ($action === 'save_file') {
        try {
            Rbac::require('files.manage');
            $filePath = (string) ($_POST['file'] ?? '');
            FileManagerService::writeFile($scope, $name, $filePath, (string) ($_POST['content'] ?? ''), $user['id']);
            jsonResponse(['ok' => true]);
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
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        flash('error', $e->getMessage());
    }

    if (in_array($action, ['trash_restore', 'trash_delete', 'trash_empty'], true)) {
        redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&trash=1');
    }
    redirect('/file_manager.php?scope=' . urlencode($scope) . '&name=' . urlencode($name) . '&path=' . urlencode($path));
}

$currentPath = (string) ($_GET['path'] ?? '');
$showHidden = ($_GET['show_hidden'] ?? '') === '1';
$searchQuery = trim((string) ($_GET['search'] ?? ''));
$showTrash = ($_GET['trash'] ?? '') === '1';

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

// Plain folder browsing (not Recycle Bin, not search results) is the one
// thing a user does over and over while working - every click used to be
// a full page reload showing ?scope=...&path=... in the address bar.
// $isAjaxBrowse requests fetch the SAME data this branch already computes
// and get back just the reusable partial's HTML instead of a full page,
// which the client swaps into #fmBrowseRoot without ever touching the
// address bar. Computed once here (not duplicated per branch) since both
// the early-exit fragment response below and the normal full-page render
// further down need the exact same $entries/$clipboard.
$isAjaxBrowse = $isAjax && !$showTrash && $searchQuery === '';

if (!$showTrash && $searchQuery === '') {
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
}

if ($isAjaxBrowse) {
    include __DIR__ . '/partials/file_manager_browse.php';
    exit;
}

// The editor is a popup reachable from any view (browse/search results) -
// its content loads/saves purely over fetch(), never by navigating to a
// dedicated page - so CodeMirror is always available, not gated behind a
// GET param.
$extraHeadHtml = '<link rel="stylesheet" href="/assets/vendor/codemirror/lib/codemirror.css">'
    . '<style>.CodeMirror{height:60vh;border:1px solid var(--bs-border-color);border-radius:.375rem;font-size:.875rem;}</style>';

$jsCsrfToken = json_encode(Csrf::token());
// Mirrors modules/terminal.sh's TERMINAL_WWW_BASE/TERMINAL_NODEAPPS_BASE -
// only used to build the Terminal popup's starting directory (see
// fmAbsolutePath below), never for anything path-security-relevant.
$jsTerminalBase = json_encode(match (true) {
    $scope === 'website' => "/var/www/{$name}",
    $scope === 'www' => '/var/www',
    $scope === 'nodeapp' => "/home/nodeapps/apps/{$name}",
    default => '/home/nodeapps/apps',
});

$extraBodyHtml = <<<HTML
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
  function modeForFilename(fileName) {
    var base = (fileName || '').toLowerCase();
    if (base === 'dockerfile') { return 'dockerfile'; }
    var ext = base.includes('.') ? base.split('.').pop() : '';
    return MODE_BY_EXT[ext] || null;
  }

  var cm = CodeMirror.fromTextArea(el, {
    lineNumbers: true,
    lineWrapping: true,
    matchBrackets: true,
    autoCloseBrackets: true,
    indentUnit: 4,
    readOnly: el.hasAttribute('readonly')
  });
  cm.on('change', function () { cm.save(); });

  var modalEl = document.getElementById('editorModal');
  var bsModal = (modalEl && typeof bootstrap !== 'undefined') ? new bootstrap.Modal(modalEl) : null;
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', function () { cm.refresh(); cm.focus(); });
  }

  function setSaveMsg(kind, text) {
    var msg = document.getElementById('editorSaveMsg');
    if (!msg) { return; }
    msg.classList.remove('d-none', 'alert-success', 'alert-danger');
    if (!kind) { msg.classList.add('d-none'); return; }
    msg.classList.add(kind === 'error' ? 'alert-danger' : 'alert-success');
    msg.textContent = text;
  }

  window.fmOpenEditor = function (relPath) {
    var url = window.location.pathname + '?scope={$scope}&name={$name}&ajax_edit=' + encodeURIComponent(relPath);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) { alert(data.error || 'Gagal membuka file'); return; }
        var titleEl = document.getElementById('editorModalFileName');
        if (titleEl) { titleEl.textContent = data.relPath; }
        var fileField = document.getElementById('editorFileField');
        if (fileField) { fileField.value = data.relPath; }
        cm.setValue(data.content);
        cm.setOption('mode', modeForFilename(data.name));
        setSaveMsg(null, '');
        if (bsModal) { bsModal.show(); }
      })
      .catch(function () { alert('Gagal membuka file (jaringan)'); });
  };

  window.fmSaveEditor = function () {
    var form = document.getElementById('editorForm');
    if (!form) { return; }
    cm.save();
    var fd = new FormData(form);
    fetch(window.location.pathname + '?scope={$scope}&name={$name}', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          setSaveMsg('success', 'Tersimpan.');
        } else {
          setSaveMsg('error', data.error || 'Gagal menyimpan');
        }
        setTimeout(function () { setSaveMsg(null, ''); }, 2500);
      })
      .catch(function () { setSaveMsg('error', 'Gagal menyimpan (jaringan)'); });
  };

  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') { return; }
    if (!modalEl || !modalEl.classList.contains('show')) { return; }
    e.preventDefault();
    window.fmSaveEditor();
  });

  document.addEventListener('click', function (e) {
    var target = e.target.closest('[data-fm-open-file]');
    if (!target) { return; }
    e.preventDefault();
    window.fmOpenEditor(target.getAttribute('data-fm-open-file'));
  });
})();
</script>
<script>
// Everything below drives #fmBrowseRoot's contents (path bar, toolbar,
// table, modals, context menu) - but that whole subtree gets replaced via
// innerHTML on every in-app folder navigation (see fmNavigateBrowse), and
// scripts injected through innerHTML never execute. So this runs ONCE from
// the initial full page load and binds every listener by delegation from
// document (or from elements that are themselves never replaced, like
// #editorModal) - never by caching a reference to anything inside
// #fmBrowseRoot, which would go stale the moment the fragment swaps.
(function () {
  document.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (ev.target.id === 'renameModal' && btn) {
      document.getElementById('renameTarget').value = btn.getAttribute('data-target');
      document.getElementById('renameNewName').value = btn.getAttribute('data-current-name');
    } else if (ev.target.id === 'deleteModal' && btn) {
      document.getElementById('deleteTarget').value = btn.getAttribute('data-target');
      document.getElementById('deleteLabel').textContent = btn.getAttribute('data-label');
    } else if (ev.target.id === 'chmodModal') {
      var singleTarget = btn ? btn.getAttribute('data-target') : null;
      var label = document.getElementById('chmodTargetLabel');
      if (singleTarget) {
        document.querySelectorAll('.fm-check').forEach(function (c) { c.checked = (c.value === singleTarget); });
        label.textContent = 'Untuk: ' + btn.getAttribute('data-label');
      } else if (label) {
        var n = document.querySelectorAll('.fm-check:checked').length;
        label.textContent = n + ' item dipilih';
      }
      var modeInput = document.getElementById('chmodModeInput');
      if (modeInput) { modeInput.value = ''; }
    }
  });

  document.addEventListener('change', function (e) {
    if (e.target.id === 'selectAll') {
      document.querySelectorAll('.fm-check').forEach(function (c) { c.checked = e.target.checked; });
      fmUpdateBulkToolbar();
    } else if (e.target.classList.contains('fm-check')) {
      fmUpdateBulkToolbar();
    }
  });

  function fmUpdateBulkToolbar() {
    var toolbar = document.getElementById('bulkToolbar');
    var searchForm = document.getElementById('fmSearchForm');
    if (!toolbar) { return; }
    var checked = document.querySelectorAll('.fm-check:checked');
    var countLabel = document.getElementById('bulkCount');
    if (checked.length > 0) {
      toolbar.classList.remove('d-none');
      toolbar.classList.add('d-flex');
      if (countLabel) { countLabel.textContent = checked.length + ' dipilih'; }
      if (searchForm) { searchForm.classList.add('d-none'); }
    } else {
      toolbar.classList.add('d-none');
      toolbar.classList.remove('d-flex');
      if (searchForm) { searchForm.classList.remove('d-none'); }
    }
  }

  function fmSelectOnly(relPath) {
    document.querySelectorAll('.fm-check').forEach(function (c) { c.checked = (c.value === relPath); });
    fmUpdateBulkToolbar();
  }

  window.fmSetBulkAction = function (action) {
    document.getElementById('bulkAction').value = action;
    document.getElementById('bulkForm').submit();
  };
  window.fmConfirmBulkDelete = function () {
    var checked = document.querySelectorAll('.fm-check:checked').length;
    if (checked === 0) { return; }
    if (!confirm('Pindahkan ' + checked + ' item ke Recycle Bin?')) { return; }
    window.fmSetBulkAction('bulk_delete');
  };
  window.fmSubmitChmod = function () {
    var modeVal = document.getElementById('chmodModeInput').value;
    if (!/^[0-7][0-7][0-7]$/.test(modeVal)) {
      alert('Mode tidak valid - harus 3 digit oktal, contoh 755 atau 644.');
      return;
    }
    document.getElementById('bulkChmodMode').value = modeVal;
    window.fmSetBulkAction('chmod');
  };

  // Drag & drop upload - reuses the same 'upload' POST action as the
  // Upload File modal, marked as an AJAX request so the server returns
  // JSON instead of flash()+redirect(). Reads the target folder from the
  // form's own hidden "path" field (live DOM read) rather than a value
  // captured at script-load time, since that field's value changes on
  // every in-app folder navigation.
  document.addEventListener('dragover', function (e) {
    if (!e.target.closest('#fmDropZone')) { return; }
    e.preventDefault();
    e.target.closest('#fmDropZone').classList.add('border-primary');
  });
  document.addEventListener('dragleave', function (e) {
    var zone = e.target.closest('#fmDropZone');
    if (zone) { zone.classList.remove('border-primary'); }
  });
  document.addEventListener('drop', function (e) {
    var zone = e.target.closest('#fmDropZone');
    if (!zone) { return; }
    e.preventDefault();
    zone.classList.remove('border-primary');
    var files = e.dataTransfer ? e.dataTransfer.files : null;
    if (!files || files.length === 0) { return; }
    fmUploadDroppedFiles(files);
  });

  function fmCurrentPath() {
    var field = document.querySelector('#bulkForm input[name="path"]');
    return field ? field.value : '';
  }

  function fmUploadDroppedFiles(files) {
    var i = 0;
    function next() {
      if (i >= files.length) { fmNavigateBrowse(window.location.pathname + '?scope={$scope}&name={$name}&path=' + encodeURIComponent(fmCurrentPath())); return; }
      var file = files[i++];
      var fd = new FormData();
      fd.append('_csrf', {$jsCsrfToken});
      fd.append('scope', '{$scope}');
      fd.append('name', '{$name}');
      fd.append('action', 'upload');
      fd.append('path', fmCurrentPath());
      fd.append('file', file);
      fetch(window.location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function () { next(); })
        .catch(function () { next(); });
    }
    next();
  }

  // In-app folder navigation (breadcrumb / folder links / show-hide toggle)
  // - fetches the SAME page as an AJAX fragment (see \$isAjaxBrowse in
  // file_manager.php) and swaps it into #fmBrowseRoot instead of letting
  // the browser navigate, so the address bar never shows ?path=... at all
  // while browsing. Falls back to a real navigation if the fetch fails for
  // any reason, so a network hiccup never leaves the user stuck.
  window.fmNavigateBrowse = function (url) {
    var root = document.getElementById('fmBrowseRoot');
    if (!root) { window.location.href = url; return; }
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('bad status'); } return r.text(); })
      .then(function (html) { root.innerHTML = html; })
      .catch(function () { window.location.href = url; });
  };

  document.addEventListener('click', function (e) {
    var navLink = e.target.closest('.fm-nav-link');
    if (!navLink) { return; }
    e.preventDefault();
    window.fmNavigateBrowse(navLink.getAttribute('href'));
  });

  // Right-click context menu on a file/folder row - mirrors the row's own
  // action icons (chmod/rename/delete/download) plus Salin/Potong (reusing
  // the same single-checkbox + bulk-action submission path a manual
  // checkbox selection would use) and Open in Terminal.
  var fmCtxRow = null;

  document.addEventListener('contextmenu', function (e) {
    var row = e.target.closest('.fm-row');
    var menu = document.getElementById('fmContextMenu');
    if (!row || !menu) { return; }
    e.preventDefault();
    fmCtxRow = {
      relPath: row.getAttribute('data-fm-relpath'),
      name: row.getAttribute('data-fm-name'),
      isDir: row.getAttribute('data-fm-is-dir') === '1'
    };
    var downloadItem = menu.querySelector('[data-fm-ctx="download"]');
    if (downloadItem) { downloadItem.style.display = fmCtxRow.isDir ? 'none' : ''; }
    var menuWidth = 220;
    var x = Math.min(e.clientX, window.innerWidth - menuWidth - 8);
    menu.style.display = 'block';
    menu.style.left = Math.max(x, 8) + 'px';
    menu.style.top = e.clientY + 'px';
  });

  document.addEventListener('click', function (e) {
    var menu = document.getElementById('fmContextMenu');
    if (menu && menu.style.display !== 'none' && !e.target.closest('#fmContextMenu')) {
      menu.style.display = 'none';
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    var menu = document.getElementById('fmContextMenu');
    if (menu) { menu.style.display = 'none'; }
  });

  document.addEventListener('click', function (e) {
    var item = e.target.closest('[data-fm-ctx]');
    if (!item || !fmCtxRow) { return; }
    var action = item.getAttribute('data-fm-ctx');
    var row = fmCtxRow;
    var menu = document.getElementById('fmContextMenu');
    if (menu) { menu.style.display = 'none'; }

    if (action === 'open') {
      if (row.isDir) {
        window.fmNavigateBrowse('/file_manager.php?scope={$scope}&name={$name}&path=' + encodeURIComponent(row.relPath));
      } else {
        window.fmOpenEditor(row.relPath);
      }
    } else if (action === 'download') {
      window.location.href = '/file_manager.php?scope={$scope}&name={$name}&download=' + encodeURIComponent(row.relPath);
    } else if (action === 'copy' || action === 'cut') {
      fmSelectOnly(row.relPath);
      window.fmSetBulkAction(action === 'cut' ? 'cut_to_clipboard' : 'copy_to_clipboard');
    } else if (action === 'chmod') {
      fmSelectOnly(row.relPath);
      var chmodEl = document.getElementById('chmodModal');
      if (chmodEl && typeof bootstrap !== 'undefined') { bootstrap.Modal.getOrCreateInstance(chmodEl).show(); }
    } else if (action === 'rename') {
      document.getElementById('renameTarget').value = row.relPath;
      document.getElementById('renameNewName').value = row.name;
      var renameEl = document.getElementById('renameModal');
      if (renameEl && typeof bootstrap !== 'undefined') { bootstrap.Modal.getOrCreateInstance(renameEl).show(); }
    } else if (action === 'delete') {
      document.getElementById('deleteTarget').value = row.relPath;
      document.getElementById('deleteLabel').textContent = row.name;
      var deleteEl = document.getElementById('deleteModal');
      if (deleteEl && typeof bootstrap !== 'undefined') { bootstrap.Modal.getOrCreateInstance(deleteEl).show(); }
    } else if (action === 'terminal' && window.fmOpenTerminal) {
      window.fmOpenTerminal(fmAbsolutePath(row.relPath, row.isDir));
    }
  });

  // Mirrors panel-exec.sh's WWW_BASE/NODEAPPS_BASE (/var/www,
  // /home/nodeapps/apps) purely to build the Terminal's starting
  // directory - not a security boundary (bwrap's own bind-mount set is
  // what actually confines the shell; if this ever drifted from the real
  // base paths, "cd" would just fail silently inside the sandbox and fall
  // back to its default directory, not escape anything).
  function fmAbsolutePath(relPath, isDir) {
    var targetRelPath = isDir ? relPath : relPath.substring(0, relPath.lastIndexOf('/') + 1).replace(/\/$/, '');
    var base = {$jsTerminalBase};
    return targetRelPath === '' ? base : base + '/' + targetRelPath;
  }
})();
</script>
HTML;

$pageTitle = 'File Manager';
include __DIR__ . '/partials/header.php';
?>

<div class="modal fade" id="editorModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-1"></i><span id="editorModalFileName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div id="editorSaveMsg" class="alert d-none py-2 mb-2"></div>
        <?php if ($canManage): ?>
        <form id="editorForm" onsubmit="return false;">
          <?= Csrf::field() ?>
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <input type="hidden" name="name" value="<?= e($name) ?>">
          <input type="hidden" name="action" value="save_file">
          <input type="hidden" name="file" id="editorFileField" value="">
          <textarea id="editorArea" name="content"></textarea>
        </form>
        <?php else: ?>
          <textarea id="editorArea" readonly></textarea>
          <p class="text-muted small mt-2 mb-0">Mode baca saja - kamu tidak memiliki izin untuk mengedit file.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" onclick="fmSaveEditor()"><i class="bi bi-save me-1"></i>Simpan <kbd class="ms-1">Ctrl+S</kbd></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($canTerminal): ?>
<div class="modal fade" id="fmTerminalModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-terminal me-1"></i>Terminal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="fmTerminalFrame" src="about:blank" style="width:100%; height:70vh; border:0;" title="Terminal"></iframe>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('fmTerminalModal');
  if (!el) { return; }
  window.fmOpenTerminal = function (absPath) {
    var frame = document.getElementById('fmTerminalFrame');
    if (frame) { frame.src = '/terminal/?arg=' + encodeURIComponent(absPath); }
    if (typeof bootstrap !== 'undefined') { bootstrap.Modal.getOrCreateInstance(el).show(); }
  };
  // Drops the iframe back to about:blank on close so the ttyd session
  // actually disconnects instead of idling in the background.
  el.addEventListener('hidden.bs.modal', function () {
    var frame = document.getElementById('fmTerminalFrame');
    if (frame) { frame.src = 'about:blank'; }
  });
})();
</script>
<?php endif; ?>

<?php if ($showTrash):

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
                  <a href="#" data-fm-open-file="<?= e($r['relPath']) ?>"><i class="bi <?= e(fm_file_icon($r['name'])) ?> <?= e(fm_file_icon_color($r['name'])) ?> me-1"></i><?= e($r['name']) ?></a>
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

  <div id="fmBrowseRoot">
    <?php include __DIR__ . '/partials/file_manager_browse.php'; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
