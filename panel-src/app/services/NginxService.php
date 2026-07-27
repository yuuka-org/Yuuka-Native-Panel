<?php
declare(strict_types=1);

final class NginxService
{
    /** @return array<int,array<string,mixed>> */
    public static function listWebsites(): array
    {
        return Database::app()->query('SELECT * FROM websites ORDER BY domain')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM websites WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on system-level failure (nginx test, fs)
     */
    public static function createWebsite(
        string $domain,
        string $phpVersion,
        ?int $userId,
        ?string $gitRepoUrl = null,
        ?string $gitBranch = null
    ): array {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!PhpService::isValidVersion($phpVersion)) {
            throw new InvalidArgumentException('Versi PHP tidak tersedia di server ini');
        }
        if ($gitRepoUrl !== null && $gitRepoUrl !== '' && !Validator::gitUrl($gitRepoUrl)) {
            throw new InvalidArgumentException('URL repository Git tidak valid (harus https://...)');
        }
        if ($gitBranch !== null && $gitBranch !== '' && !Validator::gitBranch($gitBranch)) {
            throw new InvalidArgumentException('Nama branch tidak valid');
        }

        $pdo = Database::app();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM websites WHERE domain = :d');
        $exists->execute(['d' => $domain]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new InvalidArgumentException('Domain sudah terdaftar');
        }

        if ($gitRepoUrl !== null && $gitRepoUrl !== '') {
            $clone = Executor::run('git-clone-website', [$domain, $gitRepoUrl, (string) $gitBranch], null, 120);
            if (!$clone['ok']) {
                throw new RuntimeException('Gagal clone repository Git: ' . $clone['output']);
            }
        } else {
            $mkdir = Executor::run('fs-mkdir-website', [$domain], null, 15);
            if (!$mkdir['ok']) {
                throw new RuntimeException('Gagal membuat direktori website: ' . $mkdir['output']);
            }
        }
        $documentRoot = "/var/www/{$domain}/public";

        $siteName = "site-{$domain}";
        $config = nginx_build_php_site_config($domain, $phpVersion, $documentRoot);
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Konfigurasi Nginx tidak valid: ' . $write['output']);
        }

        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs: ' . $enable['output']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO websites (domain, php_version, document_root, git_repo_url, git_branch, nginx_conf_name, is_enabled, created_by)
             VALUES (:domain, :php, :root, :git_url, :git_branch, :conf, 1, :uid)'
        );
        $stmt->execute([
            'domain' => $domain, 'php' => $phpVersion, 'root' => $documentRoot,
            'git_url' => $gitRepoUrl ?: null, 'git_branch' => $gitBranch ?: null,
            'conf' => $siteName, 'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $domainStmt = $pdo->prepare('INSERT INTO domains (domain, type, website_id) VALUES (:d, "php", :wid)');
        $domainStmt->execute(['d' => $domain, 'wid' => $id]);

        ActivityLog::record($userId, 'website.create', "Website dibuat: {$domain} (PHP {$phpVersion})"
            . ($gitRepoUrl ? ", git: {$gitRepoUrl}" : ''));

        return self::find($id);
    }

    /**
     * Fast-forward-only `git pull` in the site's own directory - fails
     * cleanly (never merges/rebases unattended) if local changes or a
     * diverged branch would require one.
     */
    public static function gitPull(int $id, ?int $userId): array
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if (empty($site['git_repo_url'])) {
            throw new InvalidArgumentException('Website ini bukan deployment Git');
        }

        $result = Executor::run('git-pull-website', [$site['domain']], null, 60);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal git pull: ' . $result['output']);
        }

        ActivityLog::record($userId, 'website.git_pull', "Git pull untuk {$site['domain']}");

        return self::gitStatus($id);
    }

    /** @return array{is_git:bool,branch?:string,commit?:string,message?:string,date?:string}|null null if the website doesn't exist */
    public static function gitStatus(int $id): ?array
    {
        $site = self::find($id);
        if ($site === null) {
            return null;
        }

        $result = Executor::run('git-status-website', [$site['domain']], null, 15);
        if (!$result['ok']) {
            return ['is_git' => false];
        }

        $status = ['is_git' => false];
        foreach (explode("\0", $result['output']) as $record) {
            if ($record === '' || !str_contains($record, "\t")) {
                continue;
            }
            [$key, $value] = explode("\t", $record, 2);
            $status[$key] = $key === 'is_git' ? ($value === 'yes') : $value;
        }

        return $status;
    }

    /**
     * Which site (website or Node.js app) currently holds the wildcard/
     * default_server slot, if any - nginx allows exactly one
     * default_server per listen address:port, so only one site across
     * BOTH tables may ever have this enabled at once.
     * @return array{type:'website'|'nodejs', id:int, name:string}|null
     */
    public static function wildcardHolder(): ?array
    {
        $pdo = Database::app();
        $w = $pdo->query('SELECT id, domain FROM websites WHERE wildcard_enabled = 1 LIMIT 1')->fetch();
        if ($w) {
            return ['type' => 'website', 'id' => (int) $w['id'], 'name' => $w['domain']];
        }
        $n = $pdo->query('SELECT id, app_name FROM nodejs_apps WHERE wildcard_enabled = 1 LIMIT 1')->fetch();
        if ($n) {
            return ['type' => 'nodejs', 'id' => (int) $n['id'], 'name' => $n['app_name']];
        }
        return null;
    }

    public static function enableWildcard(int $id, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        $holder = self::wildcardHolder();
        if ($holder !== null && !($holder['type'] === 'website' && $holder['id'] === $id)) {
            throw new InvalidArgumentException("Slot wildcard sudah dipakai oleh {$holder['name']} - nonaktifkan itu dulu (hanya satu situs yang boleh menerima domain apa saja dalam satu server).");
        }

        $siteName = 'wildcard-' . $site['nginx_conf_name'];
        $config = nginx_build_php_wildcard_site_config($site['php_version'], $site['document_root']);
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Konfigurasi Nginx tidak valid: ' . $write['output']);
        }
        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs wildcard: ' . $enable['output']);
        }

        Database::app()->prepare('UPDATE websites SET wildcard_enabled = 1 WHERE id = :id')->execute(['id' => $id]);
        ActivityLog::record($userId, 'website.wildcard_enable', "Wildcard hostname diaktifkan untuk {$site['domain']}");
    }

    public static function disableWildcard(int $id, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        nginx_delete_site('wildcard-' . $site['nginx_conf_name']);
        Database::app()->prepare('UPDATE websites SET wildcard_enabled = 0 WHERE id = :id')->execute(['id' => $id]);
        ActivityLog::record($userId, 'website.wildcard_disable', "Wildcard hostname dinonaktifkan untuk {$site['domain']}");
    }

    public static function toggleWebsite(int $id, bool $enable, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        $result = $enable ? nginx_enable_site($site['nginx_conf_name']) : nginx_disable_site($site['nginx_conf_name']);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal mengubah status situs: ' . $result['output']);
        }

        $stmt = Database::app()->prepare('UPDATE websites SET is_enabled = :e WHERE id = :id');
        $stmt->execute(['e' => $enable ? 1 : 0, 'id' => $id]);

        ActivityLog::record($userId, 'website.toggle', "Website {$site['domain']} " . ($enable ? 'diaktifkan' : 'dinonaktifkan'));
    }

    public static function deleteWebsite(int $id, bool $deleteFiles, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        nginx_delete_site($site['nginx_conf_name']);
        if ($site['wildcard_enabled']) {
            nginx_delete_site('wildcard-' . $site['nginx_conf_name']);
        }

        if ($deleteFiles) {
            Executor::run('fs-remove-website', [$site['domain']], null, 30);
        }

        $pdo = Database::app();
        $pdo->prepare('DELETE FROM domains WHERE website_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM websites WHERE id = :id')->execute(['id' => $id]);

        ActivityLog::record($userId, 'website.delete', "Website dihapus: {$site['domain']} (files_removed=" . ($deleteFiles ? 'yes' : 'no') . ')');
    }
}
