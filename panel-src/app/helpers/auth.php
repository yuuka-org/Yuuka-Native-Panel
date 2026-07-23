<?php
declare(strict_types=1);

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_WINDOW_SECONDS = 900; // 15 minutes
    private const REGEN_INTERVAL_SECONDS = 300;

    /** @return array{id:int,username:string,email:string,role:string}|null */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login.php');
        }
    }

    public static function clientIp(): string
    {
        // Only trust CF-Connecting-IP when the request actually came through
        // Cloudflare's real_ip_module (nginx already rewrote REMOTE_ADDR by
        // then); never trust client-supplied X-Forwarded-For directly here.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function isRateLimited(string $username): bool
    {
        $ip = self::clientIp();
        $pdo = Database::app();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE (username = :u OR ip_address = :ip)
               AND success = 0
               AND attempted_at > (NOW() - INTERVAL :window SECOND)'
        );
        $stmt->execute(['u' => $username, 'ip' => $ip, 'window' => self::LOCKOUT_WINDOW_SECONDS]);
        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    private static function recordAttempt(string $username, bool $success): void
    {
        $stmt = Database::app()->prepare(
            'INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, :s)'
        );
        $stmt->execute(['u' => $username, 'ip' => self::clientIp(), 's' => $success ? 1 : 0]);
    }

    public static function attempt(string $username, string $password): bool
    {
        if (self::isRateLimited($username)) {
            flash('error', 'Terlalu banyak percobaan login gagal. Coba lagi dalam beberapa menit.');
            return false;
        }

        $stmt = Database::app()->prepare(
            'SELECT id, username, email, password_hash, role, is_active FROM panel_users WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();

        if (!$row || !$row['is_active'] || !password_verify($password, $row['password_hash'])) {
            self::recordAttempt($username, false);
            ActivityLog::record(null, 'login_failed', "Login gagal untuk username: {$username}");
            flash('error', 'Username atau password salah.');
            return false;
        }

        self::recordAttempt($username, true);

        // Regenerate the session id on every successful login to prevent
        // session fixation.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'role' => $row['role'],
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regen'] = time();
        $_SESSION['login_ip'] = self::clientIp();

        $update = Database::app()->prepare(
            'UPDATE panel_users SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id'
        );
        $update->execute(['ip' => self::clientIp(), 'id' => $row['id']]);

        ActivityLog::record((int) $row['id'], 'login_success', 'Login berhasil');

        return true;
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            ActivityLog::record($user['id'], 'logout', 'Logout');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /**
     * Called on every request: enforces idle timeout, absolute lifetime,
     * and periodic session id regeneration.
     */
    public static function enforceSessionPolicy(): void
    {
        if (!self::check()) {
            return;
        }

        $now = time();
        $idleTimeout = Config::getInt('SESSION_IDLE_TIMEOUT', 900);
        $lifetime = Config::getInt('SESSION_LIFETIME', 1800);

        $lastActivity = $_SESSION['last_activity'] ?? $now;
        $loginTime = $_SESSION['login_started_at'] ?? $now;

        if (($now - $lastActivity) > $idleTimeout || ($now - $loginTime) > $lifetime) {
            self::logout();
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            flash('error', 'Sesi Anda telah berakhir, silakan login kembali.');
            redirect('/login.php');
        }

        $_SESSION['login_started_at'] = $_SESSION['login_started_at'] ?? $now;
        $_SESSION['last_activity'] = $now;

        // Skip periodic session-id regeneration for background AJAX polling
        // (dashboard.php's auto-refresh widgets call ajax_stats.php/ajax_pm2.php
        // every few seconds via fetch() with X-Requested-With, see
        // assets/js/app.js). Regenerating mid-session while multiple polls
        // are concurrently in flight is a classic race: the browser can
        // still be using the OLD session cookie on an already-dispatched
        // request right after a DIFFERENT request just deleted that old
        // session (session_regenerate_id(true) removes the old file
        // immediately) - PHP silently starts a fresh, logged-out session
        // for that stale cookie, which looks exactly like a random
        // premature logout well before the real idle/lifetime timeout.
        // The session-fixation defense this provides is secondary to the
        // regeneration already done right after login in Auth::attempt(),
        // so it's safe to only do it on real page navigations.
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if (!$isAjax) {
            $lastRegen = $_SESSION['last_regen'] ?? 0;
            if (($now - $lastRegen) > self::REGEN_INTERVAL_SECONDS) {
                session_regenerate_id(true);
                $_SESSION['last_regen'] = $now;
            }
        }
    }
}

final class ActivityLog
{
    public static function record(?int $userId, string $action, string $description = ''): void
    {
        try {
            $stmt = Database::app()->prepare(
                'INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (:u, :a, :d, :ip)'
            );
            $stmt->execute([
                'u' => $userId,
                'a' => $action,
                'd' => mb_substr($description, 0, 500),
                'ip' => Auth::clientIp(),
            ]);
        } catch (Throwable) {
            // Never let audit logging failures break the request.
        }
    }
}
