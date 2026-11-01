<?php
declare(strict_types=1);

/**
 * Clone/sinkronisasi isi proyek antar Website PHP dan Node.js App - untuk
 * satu repo Git yang berisi bagian PHP dan Node sekaligus. Hanya menambah/
 * menimpa file (tidak menghapus apa pun di tujuan) dan tidak menyentuh
 * node_modules, .env, ecosystem.config.cjs; lihat op_project_clone.
 */
final class ProjectCloneService
{
    /**
     * Semua kemungkinan tujuan/sumber: "website:<id>" / "nodeapp:<id>" => label.
     * @return array<string,array{kind:string,id:int,dir:string,label:string}>
     */
    public static function targets(): array
    {
        $out = [];
        foreach (NginxService::listWebsites() as $site) {
            $out['website:' . $site['id']] = [
                'kind' => 'website', 'id' => (int) $site['id'],
                'dir' => NginxService::siteDirName($site),
                'label' => 'Website PHP - ' . $site['domain'],
            ];
        }
        foreach (NodeService::listRegisteredApps() as $app) {
            $out['nodeapp:' . $app['id']] = [
                'kind' => 'nodeapp', 'id' => (int) $app['id'],
                'dir' => (string) $app['app_name'],
                'label' => 'Node.js - ' . $app['app_name'],
            ];
        }
        return $out;
    }

    /**
     * Isi level teratas proyek sumber (untuk memilih folder/file yang mau di-clone),
     * tanpa item yang memang tidak pernah ikut disalin.
     * @return array<int,array{name:string,type:string}>
     */
    public static function entries(string $srcKey): array
    {
        $targets = self::targets();
        if (!isset($targets[$srcKey])) {
            throw new InvalidArgumentException('Sumber tidak ditemukan');
        }
        $t = $targets[$srcKey];
        $skip = ['node_modules', '.git', '.env', 'ecosystem.config.cjs', '.trash'];
        $out = [];
        foreach (FileManagerService::listDir($t['kind'], $t['dir'], '') as $e) {
            if (!in_array($e['name'], $skip, true)) {
                $out[] = ['name' => $e['name'], 'type' => $e['type']];
            }
        }
        return $out;
    }

    /**
     * Path terpilih dari form Clone: hanya berlaku bila mode "folder tertentu";
     * gabungan centang daftar (paths[]) + kolom path tambahan (satu per baris).
     * @return string[]
     */
    public static function pathsFromPost(array $post): array
    {
        if (($post['mode'] ?? 'all') !== 'selected') {
            return [];
        }
        $paths = array_map('strval', (array) ($post['paths'] ?? []));
        foreach (preg_split('/\R/', (string) ($post['extra_paths'] ?? '')) ?: [] as $line) {
            $paths[] = $line;
        }
        $paths = array_filter(array_map('trim', $paths), static fn(string $p): bool => $p !== '');
        if (empty($paths)) {
            throw new InvalidArgumentException('Pilih minimal satu folder/file untuk di-clone, atau pilih mode "Seluruh proyek"');
        }
        return array_values($paths);
    }

    /** @param string[] $paths kosong = seluruh proyek; isi = hanya path relatif itu */
    public static function clone(string $srcKey, string $destKey, bool $withGit, ?int $userId, array $paths = []): void
    {
        $targets = self::targets();
        if (!isset($targets[$srcKey]) || !isset($targets[$destKey])) {
            throw new InvalidArgumentException('Sumber atau tujuan tidak ditemukan');
        }
        if ($srcKey === $destKey) {
            throw new InvalidArgumentException('Sumber dan tujuan tidak boleh sama');
        }
        $src = $targets[$srcKey];
        $dest = $targets[$destKey];

        $clean = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (!Validator::relativeFilePath($path) || str_contains($path, '..')) {
                throw new InvalidArgumentException("Path tidak valid: {$path}");
            }
            $clean[] = $path;
        }
        $clean = array_slice(array_values(array_unique($clean)), 0, 200);

        $args = [$src['kind'], $src['dir'], $dest['kind'], $dest['dir'], $withGit ? 'with-git' : 'no-git'];
        array_push($args, ...$clean);
        $result = Executor::run('project-clone', $args, null, 300);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal clone: ' . $result['output']);
        }
        ActivityLog::record($userId, 'project.clone', "Clone proyek: {$src['label']} -> {$dest['label']}"
            . ($clean ? ' [' . implode(', ', $clean) . ']' : ' [seluruh proyek]'));
    }
}
