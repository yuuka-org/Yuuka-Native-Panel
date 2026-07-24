<?php
declare(strict_types=1);

/**
 * Browse/upload/download/edit/extract files inside a website's document
 * root or a Node.js app's project directory. The panel process itself
 * cannot read files owned by www-data/nodeapps - every operation here goes
 * through the privileged files-* subcommands in panel-exec.sh, which apply
 * the same path-confinement (realpath containment) guarantees already used
 * elsewhere in the codebase.
 */
final class FileManagerService
{
    private const SCOPE_WEBSITE = 'website';
    private const SCOPE_NODEAPP = 'nodeapp';

    /**
     * @return array{scope:string,name:string} validated scope/name pair.
     * For root-browse scopes (www/nodeapps) "name" is a fixed placeholder
     * ("root") and carries no meaning - the base directory is the entire
     * document-root / node-app base, not one specific site/app.
     */
    public static function assertScope(string $scope, string $name): array
    {
        if (!Validator::fileManagerScope($scope)) {
            throw new InvalidArgumentException('Scope File Manager tidak dikenal');
        }
        if (Validator::fileManagerRootScope($scope)) {
            return ['scope' => $scope, 'name' => 'root'];
        }
        if ($scope === self::SCOPE_WEBSITE && !Validator::domain($name)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if ($scope === self::SCOPE_NODEAPP && !Validator::appName($name)) {
            throw new InvalidArgumentException('Nama aplikasi tidak valid');
        }
        return ['scope' => $scope, 'name' => $name];
    }

    public static function isRootScope(string $scope): bool
    {
        return Validator::fileManagerRootScope($scope);
    }

    /**
     * True when $relPath is a bare top-level entry inside a root-browse
     * scope (www/nodeapps) that no longer matches any registered website/
     * app. Deleting a website/app via the proper menu removes its DB row
     * and Nginx/PM2 config but deliberately leaves the files on disk -
     * panel-exec.sh's root-scope guard blocks ANY top-level folder
     * mutation there (it has no DB access to tell "still registered"
     * apart from "leftover orphan"), so only when this returns true do the
     * FileManagerService methods below pass the "orphan-confirmed" marker
     * that lets panel-exec.sh bypass that guard for this one call.
     */
    private static function isOrphanedRootEntry(string $scope, string $relPath): bool
    {
        if (!self::isRootScope($scope) || $relPath === '' || str_contains($relPath, '/')) {
            return false;
        }
        if ($scope === 'www') {
            $stmt = Database::app()->prepare('SELECT 1 FROM websites WHERE domain = :v');
        } else {
            $stmt = Database::app()->prepare('SELECT 1 FROM nodejs_apps WHERE app_name = :v');
        }
        $stmt->execute(['v' => $relPath]);
        return $stmt->fetch() === false;
    }

    private static function assertPath(string $relPath): void
    {
        if (!Validator::relativeFilePath($relPath)) {
            throw new InvalidArgumentException('Path tidak valid');
        }
    }

    private static function maxUploadBytes(): int
    {
        return Config::getInt('FILEMANAGER_MAX_UPLOAD_MB', 100) * 1024 * 1024;
    }

    /**
     * @return array<int,array{name:string,type:string,size:int,mtime:int,mode:string}>
     *         sorted directories first, then alphabetically.
     */
    public static function listDir(string $scope, string $name, string $relPath): array
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);

        $result = Executor::run('files-list', [$scope, $name, $relPath], null, 15);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal membaca direktori: ' . $result['output']);
        }

        $entries = [];
        // NUL-terminated records (see panel-exec.sh's op_files_list) -
        // split on "\0", not "\n": a filename can legally contain a
        // literal newline byte, which would otherwise make one real
        // record look like two rows to explode("\n", ...).
        foreach (explode("\0", $result['output']) as $record) {
            if ($record === '') {
                continue;
            }
            $parts = explode("\t", $record, 5);
            if (count($parts) !== 5) {
                continue;
            }
            [$type, $size, $mtime, $mode, $entryName] = $parts;
            $entries[] = [
                'name' => $entryName,
                'type' => $type === 'd' ? 'dir' : 'file',
                'size' => (int) $size,
                'mtime' => (int) $mtime,
                'mode' => $mode,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    /** Raw file bytes - caller decides text-vs-binary handling. */
    public static function readFile(string $scope, string $name, string $relPath): string
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($relPath === '') {
            throw new InvalidArgumentException('Path file wajib diisi');
        }

        $result = Executor::run('files-read', [$scope, $name, $relPath], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal membaca file: ' . $result['output']);
        }
        return $result['output'];
    }

    /** Heuristic only - used to decide whether the UI offers an edit link. */
    public static function looksLikeText(string $content): bool
    {
        if ($content === '') {
            return true;
        }
        return !str_contains(substr($content, 0, 8192), "\0");
    }

    public static function writeFile(string $scope, string $name, string $relPath, string $content, ?int $userId): void
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($relPath === '') {
            throw new InvalidArgumentException('Path file wajib diisi');
        }
        if (strlen($content) > self::maxUploadBytes()) {
            throw new InvalidArgumentException('Ukuran file melebihi batas FILEMANAGER_MAX_UPLOAD_MB');
        }

        $result = Executor::run('files-write', [$scope, $name, $relPath], $content, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menulis file: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.write', "File ditulis: {$scope}/{$name}/{$relPath}");
    }

    public static function mkdir(string $scope, string $name, string $relPath, ?int $userId): void
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($relPath === '') {
            throw new InvalidArgumentException('Nama folder wajib diisi');
        }

        $result = Executor::run('files-mkdir', [$scope, $name, $relPath], null, 15);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal membuat folder: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.mkdir', "Folder dibuat: {$scope}/{$name}/{$relPath}");
    }

    public static function delete(string $scope, string $name, string $relPath, ?int $userId): void
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($relPath === '') {
            throw new InvalidArgumentException('Tidak bisa menghapus direktori utama');
        }

        $args = [$scope, $name, $relPath];
        if (self::isOrphanedRootEntry($scope, $relPath)) {
            $args[] = 'orphan-confirmed';
        }
        $result = Executor::run('files-delete', $args, null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menghapus: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.delete', "Dihapus: {$scope}/{$name}/{$relPath}");
    }

    /** Same-directory rename only - relPath is the existing item, newName is a bare filename. */
    public static function rename(string $scope, string $name, string $relPath, string $newName, ?int $userId): void
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($relPath === '') {
            throw new InvalidArgumentException('Tidak bisa mengganti nama direktori utama');
        }
        if (!Validator::fileBaseName($newName)) {
            throw new InvalidArgumentException('Nama baru tidak valid');
        }

        $args = [$scope, $name, $relPath, $newName];
        if (self::isOrphanedRootEntry($scope, $relPath)) {
            $args[] = 'orphan-confirmed';
        }
        $result = Executor::run('files-rename', $args, null, 15);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mengganti nama: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.rename', "Rename: {$scope}/{$name}/{$relPath} -> {$newName}");
    }

    public static function extractZip(string $scope, string $name, string $relPath, string $zipBytes, ?int $userId): void
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($zipBytes === '') {
            throw new InvalidArgumentException('File ZIP kosong');
        }
        if (strlen($zipBytes) > self::maxUploadBytes()) {
            throw new InvalidArgumentException('Ukuran ZIP melebihi batas FILEMANAGER_MAX_UPLOAD_MB');
        }

        $result = Executor::run('files-extract-zip', [$scope, $name, $relPath], $zipBytes, 120);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mengekstrak ZIP: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.extract_zip', "ZIP diekstrak ke: {$scope}/{$name}/{$relPath}");
    }

    /** @return array<int,array{name:string,relPath:string,type:string,size:int,mtime:int}> */
    public static function search(string $scope, string $name, string $query): array
    {
        self::assertScope($scope, $name);
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Kata kunci pencarian wajib diisi');
        }
        if (strlen($query) > 200) {
            throw new InvalidArgumentException('Kata kunci terlalu panjang');
        }

        $result = Executor::run('files-search', [$scope, $name, $query], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mencari file: ' . $result['output']);
        }

        $entries = [];
        foreach (explode("\0", $result['output']) as $record) {
            if ($record === '') {
                continue;
            }
            $parts = explode("\t", $record, 4);
            if (count($parts) !== 4) {
                continue;
            }
            [$type, $size, $mtime, $relPath] = $parts;
            $entries[] = [
                'name' => basename($relPath),
                'relPath' => $relPath,
                'type' => $type === 'd' ? 'dir' : 'file',
                'size' => (int) $size,
                'mtime' => (int) $mtime,
            ];
        }
        return $entries;
    }

    public static function copy(
        string $srcScope,
        string $srcName,
        string $srcRelPath,
        string $destScope,
        string $destName,
        string $destRelPath,
        ?int $userId
    ): void {
        self::assertCopyMoveArgs($srcScope, $srcName, $srcRelPath, $destScope, $destName, $destRelPath);
        $args = [$srcScope, $srcName, $srcRelPath, $destScope, $destName, $destRelPath];
        if (self::isOrphanedRootEntry($srcScope, $srcRelPath)) {
            $args[] = 'orphan-confirmed';
        }
        $result = Executor::run('files-copy', $args, null, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menyalin: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.copy', "Salin: {$srcScope}/{$srcName}/{$srcRelPath} -> {$destScope}/{$destName}/{$destRelPath}");
    }

    public static function move(
        string $srcScope,
        string $srcName,
        string $srcRelPath,
        string $destScope,
        string $destName,
        string $destRelPath,
        ?int $userId
    ): void {
        self::assertCopyMoveArgs($srcScope, $srcName, $srcRelPath, $destScope, $destName, $destRelPath);
        $args = [$srcScope, $srcName, $srcRelPath, $destScope, $destName, $destRelPath];
        if (self::isOrphanedRootEntry($srcScope, $srcRelPath)) {
            $args[] = 'orphan-confirmed';
        }
        $result = Executor::run('files-move', $args, null, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal memindahkan: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.move', "Pindah: {$srcScope}/{$srcName}/{$srcRelPath} -> {$destScope}/{$destName}/{$destRelPath}");
    }

    private static function assertCopyMoveArgs(
        string $srcScope,
        string $srcName,
        string $srcRelPath,
        string $destScope,
        string $destName,
        string $destRelPath
    ): void {
        self::assertScope($srcScope, $srcName);
        self::assertScope($destScope, $destName);
        self::assertPath($srcRelPath);
        self::assertPath($destRelPath);
        if ($srcRelPath === '' || $destRelPath === '') {
            throw new InvalidArgumentException('Path sumber dan tujuan wajib diisi');
        }
    }

    public static function chmod(string $scope, string $name, string $relPath, string $mode, ?int $userId): void
    {
        self::assertScope($scope, $name);
        self::assertPath($relPath);
        if ($relPath === '') {
            throw new InvalidArgumentException('Path wajib diisi');
        }
        if (!Validator::chmodMode($mode)) {
            throw new InvalidArgumentException('Mode izin tidak valid (3 digit oktal, tanpa izin tulis untuk \'other\')');
        }

        $args = [$scope, $name, $relPath, $mode];
        if (self::isOrphanedRootEntry($scope, $relPath)) {
            $args[] = 'orphan-confirmed';
        }
        $result = Executor::run('files-chmod', $args, null, 15);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mengubah izin: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.chmod', "Ubah izin {$scope}/{$name}/{$relPath} ke {$mode}");
    }

    /** @return array<int,array{name:string,type:string,size:int,mtime:int,origPath:string}> */
    public static function trashList(string $scope, string $name): array
    {
        self::assertScope($scope, $name);

        $result = Executor::run('files-trash-list', [$scope, $name], null, 15);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal membaca Recycle Bin: ' . $result['output']);
        }

        $entries = [];
        foreach (explode("\0", $result['output']) as $record) {
            if ($record === '') {
                continue;
            }
            $parts = explode("\t", $record, 5);
            if (count($parts) !== 5) {
                continue;
            }
            [$type, $size, $mtime, $entryName, $origPath] = $parts;
            $entries[] = [
                'name' => $entryName,
                'type' => $type === 'd' ? 'dir' : 'file',
                'size' => (int) $size,
                'mtime' => (int) $mtime,
                'origPath' => $origPath,
            ];
        }
        usort($entries, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $entries;
    }

    public static function trashRestore(string $scope, string $name, string $trashEntry, ?int $userId): void
    {
        self::assertScope($scope, $name);
        if (!Validator::fileBaseName($trashEntry)) {
            throw new InvalidArgumentException('Item trash tidak valid');
        }

        $result = Executor::run('files-trash-restore', [$scope, $name, $trashEntry], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal memulihkan: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.trash_restore', "Pulihkan dari Recycle Bin: {$scope}/{$name}/{$trashEntry}");
    }

    public static function trashDelete(string $scope, string $name, string $trashEntry, ?int $userId): void
    {
        self::assertScope($scope, $name);
        if (!Validator::fileBaseName($trashEntry)) {
            throw new InvalidArgumentException('Item trash tidak valid');
        }

        $result = Executor::run('files-trash-delete', [$scope, $name, $trashEntry], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menghapus permanen: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.trash_delete', "Hapus permanen dari Recycle Bin: {$scope}/{$name}/{$trashEntry}");
    }

    public static function trashEmpty(string $scope, string $name, ?int $userId): void
    {
        self::assertScope($scope, $name);

        $result = Executor::run('files-trash-empty', [$scope, $name], null, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mengosongkan Recycle Bin: ' . $result['output']);
        }
        ActivityLog::record($userId, 'files.trash_empty', "Kosongkan Recycle Bin: {$scope}/{$name}");
    }
}
