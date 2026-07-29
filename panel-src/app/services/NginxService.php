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

    /** Builds the $options array nginx_build_php_site_config() expects from a `websites` row's current settings. */
    private static function siteOptions(array $site): array
    {
        return [
            'default_index' => (string) ($site['default_index'] ?? ''),
            'custom_rewrite_rules' => (string) ($site['custom_rewrite_rules'] ?? ''),
            'redirect_target' => ((bool) ($site['redirect_enabled'] ?? false)) ? $site['redirect_target'] : null,
            'rate_limit' => ((bool) ($site['rate_limit_enabled'] ?? false))
                ? ['rps' => (int) $site['rate_limit_rps'], 'burst' => (int) $site['rate_limit_burst']]
                : null,
            'hotlink' => ((bool) ($site['hotlink_protection_enabled'] ?? false))
                ? ['extensions' => (string) $site['hotlink_extensions'], 'referrers' => (string) ($site['hotlink_allowed_referrers'] ?? '')]
                : null,
            'reverse_proxies' => self::listReverseProxies((int) $site['id']),
        ];
    }

    private static function writeAndEnable(string $siteName, string $config): void
    {
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Konfigurasi Nginx tidak valid: ' . $write['output']);
        }
        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs: ' . $enable['output']);
        }
    }

    /** Regenerates ONE domain's Nginx site config from $site's current settings (php_version/document_root/advanced options all shared site-wide). */
    private static function regenerateDomainConfig(array $site, string $domain, string $siteName): void
    {
        $config = nginx_build_php_site_config($domain, $site['php_version'], $site['document_root'], self::siteOptions($site));
        self::writeAndEnable($siteName, $config);
    }

    /** Regenerates the primary domain's config plus every additional domain this site owns (see addDomain()). */
    private static function regenerateAllConfigs(array $site): void
    {
        self::regenerateDomainConfig($site, $site['domain'], $site['nginx_conf_name']);
        foreach (self::listDomains((int) $site['id']) as $d) {
            if ($d['domain'] === $site['domain']) {
                continue;
            }
            self::regenerateDomainConfig($site, $d['domain'], "site-{$d['domain']}");
        }
    }

    /**
     * `limit_req_zone` only lives in ONE shared conf.d file (nginx.php's
     * nginx_build_rate_limit_zones_config) - fully regenerated from every
     * website's CURRENT setting every time any one of them changes, so a
     * site that just turned Traffic Control off also has its now-unused
     * zone line dropped instead of left behind as cruft.
     */
    private static function regenerateRateLimitZones(): void
    {
        $rows = Database::app()->query('SELECT domain, rate_limit_rps FROM websites WHERE rate_limit_enabled = 1')->fetchAll();
        $config = nginx_build_rate_limit_zones_config($rows);
        $result = Executor::run('nginx-write-ratelimit-zones', [], $config, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menerapkan Traffic Control: ' . $result['output']);
        }
    }

    /**
     * Domain/PHP version/Document Root editing (previously impossible -
     * the only way to fix a typo'd domain was delete+recreate the whole
     * site). Renaming Domain does NOT move any files on disk - Document
     * Root is edited independently in the same form and validated only
     * against living under /var/www/ in general, not tied to one
     * specific domain's own folder (see Validator::documentRoot).
     */
    public static function updateWebsite(int $id, string $newDomain, string $phpVersion, string $documentRoot, ?int $userId): array
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if (!Validator::domain($newDomain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!PhpService::isValidVersion($phpVersion)) {
            throw new InvalidArgumentException('Versi PHP tidak tersedia di server ini');
        }
        if (!Validator::documentRoot($documentRoot)) {
            throw new InvalidArgumentException('Document Root harus berada di dalam /var/www/');
        }

        $pdo = Database::app();
        $domainChanged = $newDomain !== $site['domain'];
        $newSiteName = "site-{$newDomain}";

        if ($domainChanged) {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain = :d');
            $dup->execute(['d' => $newDomain]);
            if ((int) $dup->fetchColumn() > 0) {
                throw new InvalidArgumentException("Domain {$newDomain} sudah dipakai website/aplikasi lain");
            }
        }

        // Write+validate the NEW config first (nginx -t) before touching
        // anything belonging to the old domain - a rejected config (bad
        // PHP version/root combo, syntax problem) never leaves the site
        // with nothing serving it.
        $updated = array_merge($site, ['domain' => $newDomain, 'php_version' => $phpVersion, 'document_root' => $documentRoot]);
        self::regenerateDomainConfig($updated, $newDomain, $newSiteName);

        if ($domainChanged) {
            if ($newSiteName !== $site['nginx_conf_name']) {
                nginx_delete_site($site['nginx_conf_name']);
            }
            if ((bool) $site['wildcard_enabled']) {
                nginx_delete_site('wildcard-' . $site['nginx_conf_name']);
                self::writeAndEnable('wildcard-' . $newSiteName, nginx_build_php_wildcard_site_config($phpVersion, $documentRoot, (int) $site['wildcard_port']));
            }
        }

        $sql = 'UPDATE websites SET domain = :d, php_version = :p, document_root = :r, nginx_conf_name = :c'
            . ($domainChanged ? ', ssl_enabled = 0' : '') . ' WHERE id = :id';
        $pdo->prepare($sql)->execute(['d' => $newDomain, 'p' => $phpVersion, 'r' => $documentRoot, 'c' => $newSiteName, 'id' => $id]);

        if ($domainChanged) {
            $pdo->prepare('UPDATE domains SET domain = :d WHERE domain = :old AND website_id = :id')
                ->execute(['d' => $newDomain, 'old' => $site['domain'], 'id' => $id]);
        }

        // Additional domains (see addDomain()) share php_version/document_root/
        // advanced settings with the primary - keep their configs in sync too.
        $fresh = self::find($id);
        foreach (self::listDomains($id) as $d) {
            if ($d['domain'] === $newDomain) {
                continue;
            }
            self::regenerateDomainConfig($fresh, $d['domain'], "site-{$d['domain']}");
        }

        ActivityLog::record(
            $userId,
            'website.update',
            "Website diperbarui: {$site['domain']}" . ($domainChanged ? " -> {$newDomain}" : '') . ", PHP {$phpVersion}, root {$documentRoot}"
                . ($domainChanged && $site['ssl_enabled'] ? ' (SSL dinonaktifkan otomatis - terbitkan ulang untuk domain baru)' : '')
        );

        return self::find($id);
    }

    /** @return array<int,array<string,mixed>> */
    public static function listDomains(int $websiteId): array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE website_id = :id ORDER BY domain');
        $stmt->execute(['id' => $websiteId]);
        return $stmt->fetchAll();
    }

    public static function countDomains(int $websiteId): int
    {
        $stmt = Database::app()->prepare('SELECT COUNT(*) FROM domains WHERE website_id = :id');
        $stmt->execute(['id' => $websiteId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Adds an extra domain serving the SAME document_root/php_version/
     * advanced settings as the site's primary domain - its own separate
     * Nginx site file (mirrors NodeService::addDomain's equivalent for
     * Node.js apps), not a redirect/alias.
     */
    public static function addDomain(int $id, string $domain, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }

        $pdo = Database::app();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain = :d');
        $dup->execute(['d' => $domain]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException("Domain {$domain} sudah dipakai website/aplikasi lain");
        }

        self::regenerateDomainConfig($site, $domain, "site-{$domain}");

        $pdo->prepare('INSERT INTO domains (domain, type, website_id) VALUES (:d, "php", :id)')
            ->execute(['d' => $domain, 'id' => $id]);

        ActivityLog::record($userId, 'website.domain_add', "Domain ditambahkan ke {$site['domain']}: {$domain}");
    }

    public static function removeDomain(int $id, string $domain, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if ($domain === $site['domain']) {
            throw new InvalidArgumentException('Domain utama tidak bisa dihapus dari sini - ubah lewat tab Umum, atau hapus seluruh website.');
        }

        $pdo = Database::app();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM domains WHERE domain = :d AND website_id = :id');
        $stmt->execute(['d' => $domain, 'id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new InvalidArgumentException('Domain ini bukan milik website ini');
        }

        nginx_delete_site("site-{$domain}");
        $pdo->prepare('DELETE FROM domains WHERE domain = :d AND website_id = :id')->execute(['d' => $domain, 'id' => $id]);

        ActivityLog::record($userId, 'website.domain_remove', "Domain dihapus dari {$site['domain']}: {$domain}");
    }

    /** @return array<int,array<string,mixed>> */
    public static function listReverseProxies(int $websiteId): array
    {
        $stmt = Database::app()->prepare('SELECT * FROM website_reverse_proxies WHERE website_id = :id ORDER BY path_prefix');
        $stmt->execute(['id' => $websiteId]);
        return $stmt->fetchAll();
    }

    public static function addReverseProxy(int $id, string $pathPrefix, string $targetUrl, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if (!Validator::urlPathPrefix($pathPrefix)) {
            throw new InvalidArgumentException('Path tidak valid (harus diawali "/")');
        }
        if (!Validator::targetUrl($targetUrl)) {
            throw new InvalidArgumentException('URL tujuan tidak valid');
        }

        $pdo = Database::app();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM website_reverse_proxies WHERE website_id = :id AND path_prefix = :p');
        $dup->execute(['id' => $id, 'p' => $pathPrefix]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('Path ini sudah punya aturan Reverse Proxy');
        }

        $pdo->prepare('INSERT INTO website_reverse_proxies (website_id, path_prefix, target_url) VALUES (:id, :p, :t)')
            ->execute(['id' => $id, 'p' => $pathPrefix, 't' => $targetUrl]);

        self::regenerateAllConfigs(self::find($id));
        ActivityLog::record($userId, 'website.reverse_proxy_add', "Reverse Proxy ditambahkan ke {$site['domain']}: {$pathPrefix} -> {$targetUrl}");
    }

    public static function removeReverseProxy(int $id, int $proxyId, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        $stmt = Database::app()->prepare('DELETE FROM website_reverse_proxies WHERE id = :pid AND website_id = :id');
        $stmt->execute(['pid' => $proxyId, 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Aturan Reverse Proxy tidak ditemukan');
        }

        self::regenerateAllConfigs(self::find($id));
        ActivityLog::record($userId, 'website.reverse_proxy_remove', "Reverse Proxy dihapus dari {$site['domain']}");
    }

    /**
     * Traffic Control (rate limit) / URL Rewrite / Default Index /
     * Redirect / Hotlink Protection - one form, since they all just edit
     * columns on the same `websites` row and all require the exact same
     * "validate, save, regenerate every domain's Nginx config" flow.
     */
    public static function updateAdvanced(
        int $id,
        string $defaultIndex,
        string $customRewriteRules,
        bool $redirectEnabled,
        string $redirectTarget,
        bool $rateLimitEnabled,
        int $rateLimitRps,
        int $rateLimitBurst,
        bool $hotlinkEnabled,
        string $hotlinkExtensions,
        string $hotlinkReferrers,
        ?int $userId
    ): array {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if ($defaultIndex !== '' && !Validator::indexFileList($defaultIndex)) {
            throw new InvalidArgumentException('Default Index tidak valid (contoh: "index.php index.html")');
        }
        if ($redirectEnabled && !Validator::targetUrl($redirectTarget)) {
            throw new InvalidArgumentException('URL tujuan Redirect tidak valid (harus http:// atau https://)');
        }
        if ($rateLimitEnabled && ($rateLimitRps < 1 || $rateLimitRps > 10000 || $rateLimitBurst < 0 || $rateLimitBurst > 10000)) {
            throw new InvalidArgumentException('Nilai Traffic Control di luar batas wajar');
        }
        if ($hotlinkEnabled) {
            if (!Validator::extensionList($hotlinkExtensions)) {
                throw new InvalidArgumentException('Daftar ekstensi Hotlink Protection tidak valid');
            }
            if (!Validator::referrerList($hotlinkReferrers)) {
                throw new InvalidArgumentException('Daftar allowed referrer tidak valid');
            }
        }

        $pdo = Database::app();
        $stmt = $pdo->prepare(
            'UPDATE websites SET default_index = :di, custom_rewrite_rules = :rw,
             redirect_enabled = :re, redirect_target = :rt,
             rate_limit_enabled = :rle, rate_limit_rps = :rps, rate_limit_burst = :burst,
             hotlink_protection_enabled = :he, hotlink_extensions = :hext, hotlink_allowed_referrers = :href
             WHERE id = :id'
        );
        $stmt->execute([
            'di' => $defaultIndex ?: null, 'rw' => $customRewriteRules ?: null,
            're' => $redirectEnabled ? 1 : 0, 'rt' => $redirectTarget ?: null,
            'rle' => $rateLimitEnabled ? 1 : 0, 'rps' => $rateLimitRps, 'burst' => $rateLimitBurst,
            'he' => $hotlinkEnabled ? 1 : 0,
            'hext' => $hotlinkExtensions ?: 'jpg|jpeg|png|gif|webp|svg|mp4|mp3|css|js',
            'href' => $hotlinkReferrers ?: null,
            'id' => $id,
        ]);

        self::regenerateRateLimitZones();
        self::regenerateAllConfigs(self::find($id));

        ActivityLog::record($userId, 'website.advanced_update', "Pengaturan lanjutan diperbarui untuk {$site['domain']}");

        return self::find($id);
    }

    /**
     * Requests-per-day for this website's PRIMARY domain, last $days days,
     * oldest first, zero-filled for any day with no traffic at all (so
     * the chart never has silent gaps).
     * @return array<int,array{date:string,count:int}>
     */
    public static function trafficDaily(int $id, int $days = 30): array
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        $result = Executor::run('log-traffic-daily', [$site['domain']], null, 30);
        $counts = [];
        if ($result['ok']) {
            foreach (explode("\n", trim($result['output'])) as $line) {
                if ($line === '' || !str_contains($line, "\t")) {
                    continue;
                }
                [$date, $count] = explode("\t", $line, 2);
                $counts[$date] = (int) $count;
            }
        }

        $days = max(1, min(90, $days));
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['date' => $date, 'count' => $counts[$date] ?? 0];
        }
        return $out;
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
     * Every site (website or Node.js app) currently holding a wildcard/
     * default_server slot. More than one may be active at once (see
     * migration 2026072901) - each gets its OWN dedicated local port, so
     * nginx's one-default_server-per-listen-port rule never actually
     * conflicts between them. Reaching any slot beyond the first (which
     * keeps the original port 80) from Cloudflare still needs its own
     * separate Tunnel instance - see wiki/Fitur-Panel.md.
     * @return array<int,array{type:'website'|'nodejs', id:int, name:string, port:int}>
     */
    public static function wildcardSlots(): array
    {
        $pdo = Database::app();
        $slots = [];
        foreach ($pdo->query('SELECT id, domain, wildcard_port FROM websites WHERE wildcard_enabled = 1')->fetchAll() as $w) {
            $slots[] = ['type' => 'website', 'id' => (int) $w['id'], 'name' => $w['domain'], 'port' => (int) $w['wildcard_port']];
        }
        foreach ($pdo->query('SELECT id, app_name, wildcard_port FROM nodejs_apps WHERE wildcard_enabled = 1')->fetchAll() as $n) {
            $slots[] = ['type' => 'nodejs', 'id' => (int) $n['id'], 'name' => $n['app_name'], 'port' => (int) $n['wildcard_port']];
        }
        return $slots;
    }

    /** Prefers port 80 (the original/only option, kept for anyone whose Tunnel is already pointed there) before allocating a new dedicated port. */
    public static function findFreeWildcardPort(): ?int
    {
        if (self::isWildcardPortFree(80)) {
            return 80;
        }
        for ($port = 8880; $port <= 8979; $port++) {
            if (self::isWildcardPortFree($port)) {
                return $port;
            }
        }
        return null;
    }

    private static function isWildcardPortFree(int $port): bool
    {
        $pdo = Database::app();
        $w = $pdo->prepare('SELECT COUNT(*) FROM websites WHERE wildcard_port = :p');
        $w->execute(['p' => $port]);
        if ((int) $w->fetchColumn() > 0) {
            return false;
        }
        $n = $pdo->prepare('SELECT COUNT(*) FROM nodejs_apps WHERE wildcard_port = :p');
        $n->execute(['p' => $port]);
        if ((int) $n->fetchColumn() > 0) {
            return false;
        }
        // Port 80 is nginx's own normal shared public port (the panel and
        // every regular site already listen there) - a NEW wildcard
        // server{} block sharing it is exactly how virtual hosting
        // already works, not a fresh bind, so the OS-level free-port
        // check below (meant for a brand-new dedicated port) would
        // wrongly report it "taken" by nginx itself.
        if ($port === 80) {
            return true;
        }
        $check = Executor::run('port-check', [(string) $port], null, 10);
        return $check['ok'] && trim($check['output']) === 'free';
    }

    public static function enableWildcard(int $id, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }
        if ((bool) $site['wildcard_enabled']) {
            return;
        }

        $port = self::findFreeWildcardPort();
        if ($port === null) {
            throw new RuntimeException('Tidak ada port kosong tersedia untuk slot wildcard baru.');
        }

        $siteName = 'wildcard-' . $site['nginx_conf_name'];
        $config = nginx_build_php_wildcard_site_config($site['php_version'], $site['document_root'], $port);
        $write = nginx_write_config($siteName, $config);
        if (!$write['ok']) {
            throw new RuntimeException('Konfigurasi Nginx tidak valid: ' . $write['output']);
        }
        $enable = nginx_enable_site($siteName);
        if (!$enable['ok']) {
            throw new RuntimeException('Gagal mengaktifkan situs wildcard: ' . $enable['output']);
        }

        Database::app()->prepare('UPDATE websites SET wildcard_enabled = 1, wildcard_port = :p WHERE id = :id')
            ->execute(['p' => $port, 'id' => $id]);
        ActivityLog::record($userId, 'website.wildcard_enable', "Wildcard hostname diaktifkan untuk {$site['domain']} (port {$port})");
    }

    public static function disableWildcard(int $id, ?int $userId): void
    {
        $site = self::find($id);
        if ($site === null) {
            throw new InvalidArgumentException('Website tidak ditemukan');
        }

        nginx_delete_site('wildcard-' . $site['nginx_conf_name']);
        Database::app()->prepare('UPDATE websites SET wildcard_enabled = 0, wildcard_port = NULL WHERE id = :id')->execute(['id' => $id]);
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
