<?php
declare(strict_types=1);

final class BackupService
{
    private static function backupRoot(): string
    {
        return rtrim(Config::get('BACKUP_PATH', '/opt/server-panel/storage/backups'), '/');
    }

    /** @return array<int,array<string,mixed>> */
    public static function listBackups(): array
    {
        return Database::app()->query('SELECT * FROM backups ORDER BY created_at DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM backups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function backupDatabase(string $dbName, ?int $userId): array
    {
        if (!Validator::dbName($dbName)) {
            throw new InvalidArgumentException('Nama database tidak valid');
        }

        $filename = sprintf('db-%s-%s.sql', $dbName, date('Ymd-His'));
        $path = self::backupRoot() . '/' . $filename;

        $id = self::recordStart('database', $dbName, $path, $userId);

        $result = db_dump($dbName, $path);
        self::finalize($id, $result['ok'], $path);

        if (!$result['ok']) {
            throw new RuntimeException('Backup database gagal: ' . $result['output']);
        }

        ActivityLog::record($userId, 'backup.database', "Backup database dibuat: {$dbName}");
        return self::find($id);
    }

    public static function backupWebsite(string $domain, ?int $userId): array
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }

        $filename = sprintf('website-%s-%s.tar.gz', $domain, date('Ymd-His'));
        $path = self::backupRoot() . '/' . $filename;

        $id = self::recordStart('website', $domain, $path, $userId);
        $result = Executor::run('backup-tar-website', [$domain, $path], null, 120);
        self::finalize($id, $result['ok'], $path);

        if (!$result['ok']) {
            throw new RuntimeException('Backup website gagal: ' . $result['output']);
        }

        ActivityLog::record($userId, 'backup.website', "Backup website dibuat: {$domain}");
        return self::find($id);
    }

    public static function backupNodeApp(string $appName, ?int $userId): array
    {
        if (!Validator::appName($appName)) {
            throw new InvalidArgumentException('Nama aplikasi tidak valid');
        }

        $filename = sprintf('nodeapp-%s-%s.tar.gz', $appName, date('Ymd-His'));
        $path = self::backupRoot() . '/' . $filename;

        $id = self::recordStart('nodejs', $appName, $path, $userId);
        $result = Executor::run('backup-tar-nodeapp', [$appName, $path], null, 120);
        self::finalize($id, $result['ok'], $path);

        if (!$result['ok']) {
            throw new RuntimeException('Backup aplikasi Node.js gagal: ' . $result['output']);
        }

        ActivityLog::record($userId, 'backup.nodejs', "Backup aplikasi Node.js dibuat: {$appName}");
        return self::find($id);
    }

    public static function restore(int $backupId, ?int $userId): void
    {
        $backup = self::find($backupId);
        if ($backup === null) {
            throw new InvalidArgumentException('Backup tidak ditemukan');
        }
        if (!is_file($backup['file_path'])) {
            throw new RuntimeException('File backup hilang dari disk');
        }

        // Safety net: snapshot current state before overwriting it.
        $result = match ($backup['type']) {
            'database' => (function () use ($backup) {
                self::backupDatabase($backup['target_name'], null);
                return db_restore($backup['target_name'], $backup['file_path']);
            })(),
            'website' => (function () use ($backup) {
                self::backupWebsite($backup['target_name'], null);
                return Executor::run('restore-tar-website', [$backup['file_path'], $backup['target_name']], null, 120);
            })(),
            'nodejs' => (function () use ($backup) {
                self::backupNodeApp($backup['target_name'], null);
                return Executor::run('restore-tar-nodeapp', [$backup['file_path'], $backup['target_name']], null, 120);
            })(),
            default => throw new InvalidArgumentException('Tipe backup tidak dikenal'),
        };

        if (!$result['ok']) {
            throw new RuntimeException('Restore gagal: ' . $result['output']);
        }

        ActivityLog::record($userId, 'backup.restore', "Restore dari backup #{$backupId} ({$backup['type']}: {$backup['target_name']})");
    }

    public static function delete(int $backupId, ?int $userId): void
    {
        $backup = self::find($backupId);
        if ($backup === null) {
            return;
        }
        if (is_file($backup['file_path']) && Validator::absolutePathWithin($backup['file_path'], self::backupRoot())) {
            @unlink($backup['file_path']);
        }
        Database::app()->prepare('DELETE FROM backups WHERE id = :id')->execute(['id' => $backupId]);
        ActivityLog::record($userId, 'backup.delete', "Backup dihapus: {$backup['target_name']} ({$backup['type']})");
    }

    public static function downloadPath(int $backupId): ?string
    {
        $backup = self::find($backupId);
        if ($backup === null || !is_file($backup['file_path'])) {
            return null;
        }
        if (!Validator::absolutePathWithin($backup['file_path'], self::backupRoot())) {
            return null;
        }
        return $backup['file_path'];
    }

    private static function recordStart(string $type, string $target, string $path, ?int $userId): int
    {
        $stmt = Database::app()->prepare(
            'INSERT INTO backups (type, target_name, file_path, status, created_by) VALUES (:t, :n, :p, "running", :uid)'
        );
        $stmt->execute(['t' => $type, 'n' => $target, 'p' => $path, 'uid' => $userId]);
        return (int) Database::app()->lastInsertId();
    }

    private static function finalize(int $id, bool $success, string $path): void
    {
        $size = ($success && is_file($path)) ? filesize($path) : 0;
        $stmt = Database::app()->prepare(
            'UPDATE backups SET status = :s, size_bytes = :sz WHERE id = :id'
        );
        $stmt->execute(['s' => $success ? 'completed' : 'failed', 'sz' => $size ?: 0, 'id' => $id]);

        // Single choke point for every backup path (manual button clicks
        // AND scheduled runs via backup_scheduler_runner.php both call
        // backupDatabase()/backupWebsite()/backupNodeApp(), which all end
        // up here) - cloud upload is a no-op when nothing's configured
        // (CloudBackupService::isConfigured()), and never throws back into
        // the caller on failure, since the local copy already succeeded
        // regardless of whether the off-site mirror did.
        if ($success) {
            CloudBackupService::uploadIfConfigured($id);
        }
    }
}
