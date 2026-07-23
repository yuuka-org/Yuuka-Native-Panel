<?php
declare(strict_types=1);

/**
 * Stores database credentials (db_user + encrypted password) for
 * databases created via the panel's Database menu / App Installer, in a
 * dedicated SQLite file - deliberately a separate storage engine/file
 * from the main MariaDB server_panel database (isolates this specific
 * class of secret from the rest of the app's data by design, not just by
 * table). Password values are encrypted at rest with the same
 * AES-256-GCM scheme already used for Node.js app environment variables
 * (see EnvService) - same APP_KEY, same cipher, no new crypto code.
 */
final class DbCredentialsStore
{
    private static ?PDO $pdo = null;

    private static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = APP_PATH . '/storage/db_credentials.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS db_credentials (
                db_name TEXT PRIMARY KEY,
                db_user TEXT NOT NULL,
                password_enc TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        // Belt-and-suspenders: the file is only ever written by the panel
        // PHP-FPM pool (user "panel"), but tighten the mode explicitly in
        // case the underlying filesystem's default create mode is ever
        // more permissive than expected.
        @chmod($path, 0600);

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function save(string $dbName, string $dbUser, string $password): void
    {
        $encrypted = EnvService::encrypt($password);
        $stmt = self::pdo()->prepare(
            'INSERT INTO db_credentials (db_name, db_user, password_enc, updated_at) VALUES (:n, :u, :p, :t)
             ON CONFLICT(db_name) DO UPDATE SET db_user = excluded.db_user, password_enc = excluded.password_enc, updated_at = excluded.updated_at'
        );
        $stmt->execute(['n' => $dbName, 'u' => $dbUser, 'p' => $encrypted, 't' => date('c')]);
    }

    /** @return array{db_user:string,password:string}|null */
    public static function get(string $dbName): ?array
    {
        $stmt = self::pdo()->prepare('SELECT db_user, password_enc FROM db_credentials WHERE db_name = :n');
        $stmt->execute(['n' => $dbName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['db_user' => $row['db_user'], 'password' => EnvService::decrypt($row['password_enc'])];
    }

    public static function delete(string $dbName): void
    {
        $stmt = self::pdo()->prepare('DELETE FROM db_credentials WHERE db_name = :n');
        $stmt->execute(['n' => $dbName]);
    }
}
