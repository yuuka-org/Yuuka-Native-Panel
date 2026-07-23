<?php
declare(strict_types=1);

/**
 * Application bootstrap - loaded by every entry point in public/.
 * Wires config, secure sessions, autoloading and error handling.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to the browser

define('BASE_PATH', dirname(__DIR__) === __DIR__ ? __DIR__ : dirname(__FILE__));
define('APP_PATH', __DIR__);

require APP_PATH . '/app/config/config.php';
Config::load(APP_PATH . '/.env');

define('LOG_PATH', Config::get('LOG_PATH', APP_PATH . '/storage/logs'));

set_exception_handler(function (Throwable $e): void {
    // Short id correlated into the log line below, shown to the user - lets
    // an admin grep app-error.log for the exact incident a user reports
    // without ever exposing the exception message/trace itself to them.
    $errorId = strtoupper(bin2hex(random_bytes(4)));
    error_log('[UNCAUGHT] [' . $errorId . '] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    @file_put_contents(
        LOG_PATH . '/app-error.log',
        '[' . date('c') . '] [' . $errorId . '] ' . get_class($e) . ': ' . $e->getMessage() . "\n",
        FILE_APPEND
    );
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    $errorIdHtml = htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terjadi Kesalahan - Yuuka Server Panel</title>
<script>
(function () {
  var saved = localStorage.getItem('yuuka-theme');
  var theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-bs-theme', theme);
})();
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,#1e1b4b,#4f46e5); }
  .error-card { width: 100%; max-width: 420px; border: none; border-radius: 1rem; }
</style>
</head>
<body>
<div class="card error-card shadow-lg">
  <div class="card-body p-4 p-md-5 text-center">
    <i class="bi bi-exclamation-octagon-fill fs-1 text-danger"></i>
    <h4 class="mt-3 mb-2 fw-bold">Terjadi Kesalahan pada Server</h4>
    <p class="text-muted small mb-3">Sesuatu berjalan tidak semestinya. Tim kami sudah mencatat kejadian ini - silakan coba lagi, atau hubungi admin dengan menyertakan kode di bawah.</p>
    <div class="bg-light border rounded p-2 mb-4">
      <span class="text-muted small">Kode Referensi</span><br>
      <code class="fs-6">{$errorIdHtml}</code>
    </div>
    <div class="d-flex gap-2 justify-content-center">
      <a href="/" class="btn btn-primary"><i class="bi bi-house-door me-1"></i>Kembali ke Dashboard</a>
      <button type="button" class="btn btn-outline-secondary" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-1"></i>Coba Lagi</button>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    exit;
});

// ---------------------------------------------------------------------------
// Simple PSR-4-ish autoloader for app/{services,helpers,controllers}
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $dirs = ['services', 'controllers'];
    foreach ($dirs as $dir) {
        $path = APP_PATH . "/app/{$dir}/{$class}.php";
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

require APP_PATH . '/app/config/database.php';
foreach (glob(APP_PATH . '/app/helpers/*.php') as $helperFile) {
    require $helperFile;
}
foreach (glob(APP_PATH . '/app/scripts/*.php') as $scriptFile) {
    require $scriptFile;
}

date_default_timezone_set('UTC');

// ---------------------------------------------------------------------------
// Secure session configuration
// ---------------------------------------------------------------------------
$sessionPath = APP_PATH . '/storage/sessions';
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => Config::getBool('SESSION_SECURE_COOKIE', true),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('panel_sid');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Auth::enforceSessionPolicy();
