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

    /** @return array{scope:string,name:string} validated scope/name pair */
    public static function assertScope(string $scope, string $name): array
    {
        if (!Validator::fileManagerScope($scope)) {
            throw new InvalidArgumentException('Scope File Manager tidak dikenal');
        }
        if ($scope === self::SCOPE_WEBSITE && !Validator::domain($name)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if ($scope === self::SCOPE_NODEAPP && !Validator::appName($name)) {
            throw new InvalidArgumentException('Nama aplikasi tidak valid');
        }
        return ['scope' => $scope, 'name' => $name];
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
     * @return array<int,array{name:string,type:string,size:int,mtime:int}>
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
        foreach (explode("\n", $result['output']) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 4);
            if (count($parts) !== 4) {
                continue;
            }
            [$type, $size, $mtime, $entryName] = $parts;
            $entries[] = [
                'name' => $entryName,
                'type' => $type === 'd' ? 'dir' : 'file',
                'size' => (int) $size,
                'mtime' => (int) $mtime,
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

        $result = Executor::run('files-delete', [$scope, $name, $relPath], null, 30);
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

        $result = Executor::run('files-rename', [$scope, $name, $relPath, $newName], null, 15);
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
}
