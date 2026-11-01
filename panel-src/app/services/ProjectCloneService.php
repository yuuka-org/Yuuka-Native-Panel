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

    public static function clone(string $srcKey, string $destKey, bool $withGit, ?int $userId): void
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

        $args = [$src['kind'], $src['dir'], $dest['kind'], $dest['dir']];
        if ($withGit) {
            $args[] = 'with-git';
        }
        $result = Executor::run('project-clone', $args, null, 300);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal clone: ' . $result['output']);
        }
        ActivityLog::record($userId, 'project.clone', "Clone proyek: {$src['label']} -> {$dest['label']}");
    }
}
