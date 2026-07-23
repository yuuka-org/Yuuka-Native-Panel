<?php
declare(strict_types=1);

final class DatabaseService
{
    /** @return array<int,array<string,mixed>> */
    public static function listRegistry(): array
    {
        return Database::app()->query('SELECT * FROM databases_registry ORDER BY db_name')->fetchAll();
    }

    /** @return array<int,array{name:string,size_mb:float}> live list straight from MariaDB */
    public static function listLive(): array
    {
        return db_list();
    }

    public static function createDatabase(string $dbName, string $dbUser, string $password, ?string $note, ?int $userId): void
    {
        if (!Validator::dbName($dbName)) {
            throw new InvalidArgumentException('Nama database tidak valid (huruf, angka, underscore, maks 64 karakter)');
        }
        if (!Validator::dbUser($dbUser)) {
            throw new InvalidArgumentException('Nama user database tidak valid');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password database minimal 8 karakter');
        }

        db_create($dbName);
        db_user_create($dbUser, $password);
        db_grant_all($dbName, $dbUser);

        $stmt = Database::app()->prepare(
            'INSERT INTO databases_registry (db_name, db_user, note, created_by) VALUES (:n, :u, :note, :uid)'
        );
        $stmt->execute(['n' => $dbName, 'u' => $dbUser, 'note' => $note, 'uid' => $userId]);

        DbCredentialsStore::save($dbName, $dbUser, $password);

        ActivityLog::record($userId, 'database.create', "Database dibuat: {$dbName} (user: {$dbUser})");
    }

    public static function dropDatabase(int $registryId, ?int $userId): void
    {
        $stmt = Database::app()->prepare('SELECT * FROM databases_registry WHERE id = :id');
        $stmt->execute(['id' => $registryId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Database tidak ditemukan di registry panel');
        }

        db_drop($row['db_name']);
        db_user_drop($row['db_user']);

        Database::app()->prepare('DELETE FROM databases_registry WHERE id = :id')->execute(['id' => $registryId]);

        DbCredentialsStore::delete($row['db_name']);

        ActivityLog::record($userId, 'database.delete', "Database dihapus: {$row['db_name']}");
    }
}
