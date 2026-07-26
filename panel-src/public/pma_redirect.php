<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('database.view');

$stmt = Database::app()->prepare('SELECT setting_value FROM settings WHERE setting_key = "phpmyadmin_url"');
$stmt->execute();
$base = $stmt->fetchColumn();

if (!$base) {
    flash('error', 'URL phpMyAdmin belum dikonfigurasi. Atur di menu Pengaturan.');
    redirect('/settings');
}

// Auto-login is gated behind database.manage (admin/operator), NOT the
// looser database.view this page itself requires - a Viewer role can
// see that a database exists but must not silently gain full read-write
// MariaDB access just by clicking through to phpMyAdmin. Viewers still
// reach phpMyAdmin's URL, they just land on its normal login form
// instead of being signed in automatically.
$db = (string) ($_GET['db'] ?? '');
$creds = null;
// TEMPORARY debug logging - tracing why signon auto-login silently never
// writes a session (no exception, no PMA_single_signon_* in any session
// file produced under storage/pma-signon). Remove once root cause found.
error_log('[PMA-DEBUG] db=' . var_export($db, true)
    . ' dbNameValid=' . var_export($db !== '' && Validator::dbName($db), true)
    . ' role=' . var_export(Auth::user()['role'] ?? null, true)
    . ' canManage=' . var_export(Rbac::can(Auth::user()['role'] ?? '', 'database.manage'), true));
if ($db !== '' && Validator::dbName($db) && Rbac::can(Auth::user()['role'] ?? '', 'database.manage')) {
    $creds = DbCredentialsStore::get($db);
    error_log('[PMA-DEBUG] creds=' . ($creds === null ? 'NULL' : ('db_user=' . var_export($creds['db_user'], true) . ' passwordLen=' . strlen($creds['password']))));
}

if ($creds !== null && $creds['password'] !== '') {
    error_log('[PMA-DEBUG] calling pma_write_signon_session');
    pma_write_signon_session($creds['db_user'], $creds['password']);
    error_log('[PMA-DEBUG] pma_write_signon_session returned, cookie sent=' . var_export(headers_list(), true));
} else {
    error_log('[PMA-DEBUG] SKIPPED pma_write_signon_session - creds null or empty password');
}

$target = rtrim((string) $base, '/') . '/index.php';
if ($db !== '' && Validator::dbName($db)) {
    $target .= '?route=%2Fdatabase%2Fstructure&db=' . urlencode($db);
}

// "Path" mode phpMyAdmin lives under the panel's own host - the panel
// vhost only ever listens on plain HTTP (TLS, if any, is terminated
// upstream, e.g. Cloudflare), so the scheme baked into $base at build
// time (modules/phpmyadmin.sh always saves "http://...") can mismatch
// how the admin is actually browsing right now, bouncing the browser
// between http/https on every click. When the saved URL's host matches
// the CURRENT request's host, override its scheme with the current
// request's actual scheme (currentScheme(), response.php) instead of
// trusting the stored one. "Subdomain" mode phpMyAdmin (a genuinely
// different host) keeps whatever scheme was saved - there's no way to
// know that separate host's own TLS setup from here.
$targetParts = parse_url($target);
if (($targetParts['host'] ?? null) === ($_SERVER['HTTP_HOST'] ?? null)) {
    $target = currentScheme() . '://' . $targetParts['host']
        . ($targetParts['path'] ?? '')
        . (isset($targetParts['query']) ? '?' . $targetParts['query'] : '');
}

redirect($target);

/**
 * Bridges credentials to phpMyAdmin's 'signon' auth mechanism (see
 * vendor phpMyAdmin's AuthenticationSignon::readCredentials()): writes a
 * SEPARATE PHP session - identified by a cookie whose NAME matches
 * SignonSession in phpMyAdmin's config.inc.php ('PMASignon') - containing
 * PMA_single_signon_user / PMA_single_signon_password. Stored in
 * PHPMYADMIN_SIGNON_SESSION_DIR (modules/phpmyadmin.sh), a directory
 * phpMyAdmin's own dedicated PHP-FPM pool is also configured to read
 * session data from.
 *
 * Only reliable for "path" mode (phpMyAdmin under the panel's own
 * domain) - in "subdomain" mode the cookie set here (no explicit
 * `domain` attribute) is only visible to the panel's own hostname, not
 * phpMyAdmin's separate subdomain, so signon silently falls back to
 * phpMyAdmin's normal login form. Fixing that generically would require
 * knowing the shared parent domain between the two, which isn't always
 * derivable (the admin can pick any subdomain at install time).
 */
function pma_write_signon_session(string $dbUser, string $dbPassword): void
{
    $signonSessionName = 'PMASignon';
    $signonSessionDir = '/opt/server-panel/storage/pma-signon';

    // TEMPORARY debug logging - see matching note above this function's call site.
    error_log('[PMA-DEBUG] pma_write_signon_session: isDir=' . var_export(is_dir($signonSessionDir), true)
        . ' isWritable=' . var_export(is_writable($signonSessionDir), true)
        . ' open_basedir=' . var_export(ini_get('open_basedir'), true)
        . ' posix_uid=' . (function_exists('posix_getuid') ? posix_getuid() : 'n/a'));
    if (!is_dir($signonSessionDir) || !is_writable($signonSessionDir)) {
        // Pool/directory not provisioned (e.g. panel installed before
        // this feature existed, "yp repair panel" not run yet) - fail
        // open to phpMyAdmin's normal login screen rather than erroring.
        error_log('[PMA-DEBUG] pma_write_signon_session: BAILING OUT (dir missing or not writable)');
        return;
    }

    // Opportunistic cleanup: these sessions are only ever meant to live
    // for a few seconds (until phpMyAdmin reads and discards them), but
    // nothing else ever deletes the server-side file. This directory is
    // NOT covered by the distro's default session-gc cron (that only
    // knows about PHP's default session.save_path), so without this the
    // files would accumulate here indefinitely.
    foreach (glob($signonSessionDir . '/sess_*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 300) {
            @unlink($file);
        }
    }

    $originalSessionName = session_name();
    $originalSessionId = session_id();
    $originalSavePath = session_save_path();

    // Suspend the panel's own session so we never touch/mix its store.
    session_write_close();

    session_save_path($signonSessionDir);
    session_name($signonSessionName);
    session_id(bin2hex(random_bytes(16)));
    error_log('[PMA-DEBUG] before start: save_handler=' . var_export(ini_get('session.save_handler'), true)
        . ' save_path=' . var_export(session_save_path(), true));
    $startOk = session_start();
    error_log('[PMA-DEBUG] session_start returned=' . var_export($startOk, true));
    $_SESSION['PMA_single_signon_user'] = $dbUser;
    $_SESSION['PMA_single_signon_password'] = $dbPassword;
    $signonId = session_id();
    session_write_close();
    // PHP's files session handler creates the session file 0600 (owner-only),
    // regardless of the containing directory's own mode - the directory's
    // group-write bit (see PHPMYADMIN_SIGNON_SESSION_DIR setup in
    // modules/phpmyadmin.sh) only lets phpMyAdmin's www-data pool (a member
    // of the 'panel' group) traverse/list the directory, not read this
    // specific file's contents. Without this, phpMyAdmin's signon read
    // fails silently (permission denied) and it falls back to its SignonURL
    // (the panel's own login.php) instead of ever raising an error.
    @chmod($signonSessionDir . '/sess_' . $signonId, 0660);
    error_log('[PMA-DEBUG] after write_close: expected file=' . $signonSessionDir . '/sess_' . $signonId
        . ' exists=' . var_export(file_exists($signonSessionDir . '/sess_' . $signonId), true)
        . ' dirListing=' . var_export(glob($signonSessionDir . '/*'), true));

    setcookie($signonSessionName, $signonId, [
        'expires' => time() + 60,
        'path' => '/',
        // A 'secure' cookie is silently dropped by the browser outside an
        // HTTPS context - unlike the main panel session cookie
        // (SESSION_SECURE_COOKIE in bootstrap.php, a fixed .env setting),
        // this one specifically needs to match however THIS request
        // actually arrived (see currentScheme(), response.php), since it
        // only has to survive the single redirect hop to phpMyAdmin.
        'secure' => currentScheme() === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Restore the panel's own session exactly as it was before we
    // continue (redirect() below sends a fresh Location + exits, but
    // leaving this in a consistent state is cheap and avoids surprises
    // if this function is ever called from somewhere that keeps running).
    session_save_path($originalSavePath);
    session_name($originalSessionName);
    session_id($originalSessionId);
    session_start();
}
