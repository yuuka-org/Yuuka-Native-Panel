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
    redirect('/settings.php');
}

// Auto-login is gated behind database.manage (admin/operator), NOT the
// looser database.view this page itself requires - a Viewer role can
// see that a database exists but must not silently gain full read-write
// MariaDB access just by clicking through to phpMyAdmin. Viewers still
// reach phpMyAdmin's URL, they just land on its normal login form
// instead of being signed in automatically.
$db = (string) ($_GET['db'] ?? '');
$creds = null;
if ($db !== '' && Validator::dbName($db) && Rbac::can(Auth::user()['role'] ?? '', 'database.manage')) {
    $creds = DbCredentialsStore::get($db);
}

if ($creds !== null && $creds['password'] !== '') {
    pma_write_signon_session($creds['db_user'], $creds['password']);
}

$target = rtrim((string) $base, '/') . '/index.php';
if ($db !== '' && Validator::dbName($db)) {
    $target .= '?route=%2Fdatabase%2Fstructure&db=' . urlencode($db);
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

    if (!is_dir($signonSessionDir) || !is_writable($signonSessionDir)) {
        // Pool/directory not provisioned (e.g. panel installed before
        // this feature existed, "yp repair panel" not run yet) - fail
        // open to phpMyAdmin's normal login screen rather than erroring.
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
    session_start();
    $_SESSION['PMA_single_signon_user'] = $dbUser;
    $_SESSION['PMA_single_signon_password'] = $dbPassword;
    $signonId = session_id();
    session_write_close();

    setcookie($signonSessionName, $signonId, [
        'expires' => time() + 60,
        'path' => '/',
        'secure' => Config::getBool('SESSION_SECURE_COOKIE', true),
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
