<?php
declare(strict_types=1);

/**
 * Manages WordPress sites that App Installer already created: core
 * version/update, plugins, themes. Reuses FileManagerService's existing
 * files-* primitives for everything filesystem-related (no new
 * panel-exec.sh subcommand needed) plus a new direct PDO connection to
 * each site's OWN WordPress database (credentials from DbCredentialsStore)
 * to read/write the small set of wp_options rows that track plugin/theme
 * activation state - WordPress itself has no other API surface for that
 * short of WP-CLI, which this panel does not install.
 *
 * Every plugin/theme download still follows the same SSRF-closure
 * invariant as AppCatalog/AppInstallerService: a caller-supplied slug is
 * validated against a strict regex (Validator::wpSlug()) BEFORE it is
 * interpolated into a fixed api.wordpress.org URL - never a client URL,
 * never an unvalidated string reaching curl.
 */
final class WpManagerService
{
    /**
     * A WordPress table prefix is validated against this exact pattern
     * before it is EVER interpolated into a SQL statement as a table name
     * (`{$prefix}options` - table identifiers cannot be bound as PDO
     * parameters). This is the actual injection guard for every method
     * below that touches the tenant database, re-checked at each call site
     * rather than trusted once, since the value can originate either from
     * a regex-parsed wp-config.php or a manually admin-typed override.
     */
    private const PREFIX_PATTERN = '/^[A-Za-z0-9_]{1,20}$/';

    /** @return array<int,array<string,mixed>> */
    public static function listSites(): array
    {
        return Database::app()->query(
            "SELECT ia.website_id, w.domain, w.php_version, ia.app_version, ia.table_prefix, ia.db_name
             FROM installed_apps ia
             JOIN websites w ON w.id = ia.website_id
             WHERE ia.app_slug = 'wordpress'
             ORDER BY w.domain"
        )->fetchAll();
    }

    /** @return array<string,mixed> */
    public static function getSite(int $websiteId): array
    {
        $stmt = Database::app()->prepare(
            "SELECT ia.website_id, ia.app_version, ia.table_prefix, ia.db_name, w.domain, w.php_version
             FROM installed_apps ia
             JOIN websites w ON w.id = ia.website_id
             WHERE ia.website_id = :id AND ia.app_slug = 'wordpress'"
        );
        $stmt->execute(['id' => $websiteId]);
        $site = $stmt->fetch();
        if ($site === false) {
            throw new InvalidArgumentException('Situs WordPress tidak ditemukan');
        }
        if ($site['table_prefix'] === null) {
            $site['table_prefix'] = self::resolveTablePrefixFromConfig($site);
        }
        return $site;
    }

    /**
     * Sites installed before installed_apps.table_prefix existed have NULL
     * here - recover it by regex-parsing wp-config.php (which this panel
     * itself generated with a single, predictable `$table_prefix = '...';`
     * line via var_export()) and cache the result so this only ever runs
     * once per site. Degrades to null (not an exception) on any failure -
     * callers treat null table_prefix as "database status unknown".
     */
    private static function resolveTablePrefixFromConfig(array $site): ?string
    {
        try {
            $config = FileManagerService::readFile('website', $site['domain'], 'public/wp-config.php');
        } catch (Throwable) {
            return null;
        }
        if (!preg_match('/\$table_prefix\s*=\s*([\'"])((?:(?!\1).)*)\1\s*;/', $config, $m)) {
            return null;
        }
        $prefix = $m[2];
        if (!preg_match(self::PREFIX_PATTERN, $prefix)) {
            return null;
        }
        self::setTablePrefixOverride($site['website_id'], $prefix);
        return $prefix;
    }

    public static function setTablePrefixOverride(int $websiteId, string $prefix): void
    {
        if (!preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new InvalidArgumentException('Prefix tabel tidak valid');
        }
        Database::app()->prepare('UPDATE installed_apps SET table_prefix = :p WHERE website_id = :id')
            ->execute(['p' => $prefix, 'id' => $websiteId]);
    }

    private static function assertSafePrefix(string $prefix): void
    {
        if (!preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new RuntimeException('Prefix tabel tidak valid, tidak bisa mengakses opsi WordPress');
        }
    }

    /** Fresh PDO connection to the SITE'S OWN database - never Database::app()/provisioner(). */
    private static function tenantPdo(string $dbName): PDO
    {
        $creds = DbCredentialsStore::get($dbName);
        if ($creds === null) {
            throw new RuntimeException('Kredensial database situs tidak ditemukan');
        }
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        return new PDO($dsn, $creds['db_user'], $creds['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * @return array{active_plugins:string[],template:?string,stylesheet:?string}|null
     *         null = degraded mode (tenant DB unreachable / prefix unknown)
     *         - callers must render "status unknown" rather than crash.
     */
    public static function readWpOptions(array $site): ?array
    {
        $prefix = $site['table_prefix'] ?? null;
        if ($prefix === null || empty($site['db_name'])) {
            return null;
        }
        try {
            self::assertSafePrefix($prefix);
            $pdo = self::tenantPdo($site['db_name']);
            $table = $prefix . 'options';
            $stmt = $pdo->query("SELECT option_name, option_value FROM `{$table}` WHERE option_name IN ('active_plugins','template','stylesheet')");
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return null;
        }

        $active = [];
        $template = null;
        $stylesheet = null;
        foreach ($rows as $row) {
            if ($row['option_name'] === 'active_plugins') {
                // WordPress's own serialization format for this option -
                // this is a NEW pattern in this codebase (not a reuse of an
                // existing one), hence the explicit allowed_classes:false.
                $unserialized = @unserialize((string) $row['option_value'], ['allowed_classes' => false]);
                if (is_array($unserialized)) {
                    $active = array_values(array_filter($unserialized, 'is_string'));
                }
            } elseif ($row['option_name'] === 'template') {
                $template = (string) $row['option_value'];
            } elseif ($row['option_name'] === 'stylesheet') {
                $stylesheet = (string) $row['option_value'];
            }
        }
        return ['active_plugins' => $active, 'template' => $template, 'stylesheet' => $stylesheet];
    }

    // -------------------------------------------------------------------
    // Plugins
    // -------------------------------------------------------------------

    /** @return array<int,array{folder:string,file:string,name:string,version:?string,active:?bool}> */
    public static function listPlugins(array $site): array
    {
        $domain = $site['domain'];
        try {
            $entries = FileManagerService::listDir('website', $domain, 'wp-content/plugins');
        } catch (Throwable) {
            return [];
        }
        $options = self::readWpOptions($site);
        $active = $options['active_plugins'] ?? null;

        $plugins = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'dir') {
                continue;
            }
            $folder = $entry['name'];
            $header = self::parsePluginHeader($domain, $folder);
            if ($header === null) {
                continue;
            }
            $plugins[] = [
                'folder' => $folder,
                'file' => $header['file'],
                'name' => $header['name'],
                'version' => $header['version'],
                'active' => $active === null ? null : in_array($header['file'], $active, true),
            ];
        }
        usort($plugins, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $plugins;
    }

    /**
     * Scans a plugin folder's top-level .php files for the standard WP
     * header docblock (same convention get_plugin_data() reads) - best
     * effort only, deliberately not recursive (matches WordPress's own
     * shallow scan for the common case). A folder with no recognizable
     * header is skipped rather than guessed at.
     *
     * @return array{file:string,name:string,version:?string}|null
     */
    private static function parsePluginHeader(string $domain, string $folder): ?array
    {
        try {
            $files = FileManagerService::listDir('website', $domain, "wp-content/plugins/{$folder}");
        } catch (Throwable) {
            return null;
        }
        foreach ($files as $file) {
            if ($file['type'] !== 'file' || !str_ends_with($file['name'], '.php')) {
                continue;
            }
            try {
                $content = FileManagerService::readFile('website', $domain, "wp-content/plugins/{$folder}/{$file['name']}");
            } catch (Throwable) {
                continue;
            }
            $head = substr($content, 0, 8192);
            if (!preg_match('/Plugin Name:\s*(.+)/i', $head, $nameMatch)) {
                continue;
            }
            preg_match('/Version:\s*([0-9][0-9A-Za-z.\-]*)/i', $head, $verMatch);
            return [
                'file' => "{$folder}/{$file['name']}",
                'name' => trim(preg_replace('/\s*\*+\/\s*$/', '', trim($nameMatch[1]))),
                'version' => $verMatch[1] ?? null,
            ];
        }
        return null;
    }

    private static function assertPluginFileFormat(string $pluginFile): void
    {
        if (!preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+\.php$#', $pluginFile)) {
            throw new InvalidArgumentException('Format file plugin tidak valid');
        }
    }

    public static function togglePlugin(array $site, string $pluginFile, bool $activate, ?int $userId): void
    {
        self::assertPluginFileFormat($pluginFile);
        $prefix = $site['table_prefix'] ?? null;
        if ($prefix === null || empty($site['db_name'])) {
            throw new RuntimeException('Status database situs tidak diketahui - tidak bisa mengubah status aktif plugin');
        }
        self::assertSafePrefix($prefix);
        $pdo = self::tenantPdo($site['db_name']);
        $table = $prefix . 'options';

        $stmt = $pdo->prepare("SELECT option_value FROM `{$table}` WHERE option_name = 'active_plugins'");
        $stmt->execute();
        $current = $stmt->fetchColumn();
        $active = [];
        if ($current !== false) {
            $unserialized = @unserialize((string) $current, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                $active = array_values(array_filter($unserialized, 'is_string'));
            }
        }

        if ($activate) {
            if (!in_array($pluginFile, $active, true)) {
                $active[] = $pluginFile;
            }
        } else {
            $active = array_values(array_filter($active, static fn(string $f): bool => $f !== $pluginFile));
        }

        $pdo->prepare(
            "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES ('active_plugins', :v, 'yes')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
        )->execute(['v' => serialize($active)]);

        ActivityLog::record($userId, 'wp_manager.plugin_toggle', ($activate ? 'Aktifkan' : 'Nonaktifkan') . " plugin {$pluginFile} di {$site['domain']}");
    }

    public static function deletePlugin(array $site, string $pluginFile, bool $confirmedUnknownRisk, ?int $userId): void
    {
        self::assertPluginFileFormat($pluginFile);
        $options = self::readWpOptions($site);
        if ($options === null && !$confirmedUnknownRisk) {
            throw new RuntimeException('Status aktif plugin tidak diketahui - centang konfirmasi risiko untuk tetap menghapus');
        }
        if ($options !== null && in_array($pluginFile, $options['active_plugins'], true)) {
            throw new InvalidArgumentException('Nonaktifkan plugin ini dulu sebelum menghapusnya');
        }
        $folder = strstr($pluginFile, '/', true);
        FileManagerService::delete('website', $site['domain'], "wp-content/plugins/{$folder}", $userId);
        ActivityLog::record($userId, 'wp_manager.plugin_delete', "Hapus plugin {$pluginFile} di {$site['domain']}");
    }

    public static function installPluginBySlug(array $site, string $slug, ?int $userId): void
    {
        if (!Validator::wpSlug($slug)) {
            throw new InvalidArgumentException('Slug plugin tidak valid');
        }
        $info = self::fetchCatalogInfo('plugins', $slug);
        $zipBytes = AppInstallerService::downloadFixedUrl($info['download_url']);
        self::assertZipHasSingleFolderWithHeader($zipBytes, $slug, 'Plugin Name');
        FileManagerService::extractZip('website', $site['domain'], 'wp-content/plugins', $zipBytes, $userId);
        ActivityLog::record($userId, 'wp_manager.plugin_install', "Instal plugin {$slug} ({$info['version']}) di {$site['domain']} dari WordPress.org");
    }

    public static function installPluginByZip(array $site, string $zipBytes, ?int $userId): void
    {
        $folder = self::detectSingleFolderWithHeader($zipBytes, 'Plugin Name');
        FileManagerService::extractZip('website', $site['domain'], 'wp-content/plugins', $zipBytes, $userId);
        ActivityLog::record($userId, 'wp_manager.plugin_install', "Instal plugin {$folder} di {$site['domain']} dari upload ZIP");
    }

    /** @return array{latest_version:string,update_available:bool} */
    public static function checkPluginUpdate(string $folder, ?string $currentVersion): array
    {
        $info = self::fetchCatalogInfo('plugins', $folder);
        return [
            'latest_version' => $info['version'],
            'update_available' => $currentVersion === null || version_compare($info['version'], $currentVersion, '>'),
        ];
    }

    public static function updatePlugin(array $site, string $folder, ?int $userId): string
    {
        $info = self::fetchCatalogInfo('plugins', $folder);
        $zipBytes = AppInstallerService::downloadFixedUrl($info['download_url']);
        self::assertZipHasSingleFolderWithHeader($zipBytes, $folder, 'Plugin Name');

        // Safety net before overwriting an existing plugin's files.
        BackupService::backupWebsite($site['domain'], $userId);

        FileManagerService::extractZip('website', $site['domain'], 'wp-content/plugins', $zipBytes, $userId);
        ActivityLog::record($userId, 'wp_manager.plugin_update', "Update plugin {$folder} ke {$info['version']} di {$site['domain']}");
        return $info['version'];
    }

    // -------------------------------------------------------------------
    // Themes
    // -------------------------------------------------------------------

    /** @return array<int,array{folder:string,name:string,version:?string,active:?bool}> */
    public static function listThemes(array $site): array
    {
        $domain = $site['domain'];
        try {
            $entries = FileManagerService::listDir('website', $domain, 'wp-content/themes');
        } catch (Throwable) {
            return [];
        }
        $options = self::readWpOptions($site);
        $activeStylesheet = $options['stylesheet'] ?? null;

        $themes = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'dir') {
                continue;
            }
            $folder = $entry['name'];
            $header = self::parseThemeHeader($domain, $folder);
            if ($header === null) {
                continue;
            }
            $themes[] = [
                'folder' => $folder,
                'name' => $header['name'],
                'version' => $header['version'],
                'active' => $options === null ? null : ($activeStylesheet === $folder),
            ];
        }
        usort($themes, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $themes;
    }

    /** @return array{name:string,version:?string}|null */
    private static function parseThemeHeader(string $domain, string $folder): ?array
    {
        try {
            $content = FileManagerService::readFile('website', $domain, "wp-content/themes/{$folder}/style.css");
        } catch (Throwable) {
            return null;
        }
        $head = substr($content, 0, 8192);
        if (!preg_match('/Theme Name:\s*(.+)/i', $head, $nameMatch)) {
            return null;
        }
        preg_match('/Version:\s*([0-9][0-9A-Za-z.\-]*)/i', $head, $verMatch);
        return ['name' => trim(preg_replace('/\s*\*+\/\s*$/', '', trim($nameMatch[1]))), 'version' => $verMatch[1] ?? null];
    }

    private static function assertThemeFolderFormat(string $folder): void
    {
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $folder)) {
            throw new InvalidArgumentException('Nama folder tema tidak valid');
        }
    }

    public static function activateTheme(array $site, string $folder, ?int $userId): void
    {
        self::assertThemeFolderFormat($folder);
        $prefix = $site['table_prefix'] ?? null;
        if ($prefix === null || empty($site['db_name'])) {
            throw new RuntimeException('Status database situs tidak diketahui - tidak bisa mengaktifkan tema');
        }
        self::assertSafePrefix($prefix);
        $pdo = self::tenantPdo($site['db_name']);
        $table = $prefix . 'options';

        foreach (['template', 'stylesheet'] as $optionName) {
            $pdo->prepare(
                "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (:n, :v, 'yes')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
            )->execute(['n' => $optionName, 'v' => $folder]);
        }

        ActivityLog::record($userId, 'wp_manager.theme_activate', "Aktifkan tema {$folder} di {$site['domain']}");
    }

    public static function deleteTheme(array $site, string $folder, bool $confirmedUnknownRisk, ?int $userId): void
    {
        self::assertThemeFolderFormat($folder);
        $options = self::readWpOptions($site);
        if ($options === null && !$confirmedUnknownRisk) {
            throw new RuntimeException('Status aktif tema tidak diketahui - centang konfirmasi risiko untuk tetap menghapus');
        }
        if ($options !== null && $options['stylesheet'] === $folder) {
            throw new InvalidArgumentException('Tidak bisa menghapus tema yang sedang aktif');
        }
        FileManagerService::delete('website', $site['domain'], "wp-content/themes/{$folder}", $userId);
        ActivityLog::record($userId, 'wp_manager.theme_delete', "Hapus tema {$folder} di {$site['domain']}");
    }

    public static function installThemeBySlug(array $site, string $slug, ?int $userId): void
    {
        if (!Validator::wpSlug($slug)) {
            throw new InvalidArgumentException('Slug tema tidak valid');
        }
        $info = self::fetchCatalogInfo('themes', $slug);
        $zipBytes = AppInstallerService::downloadFixedUrl($info['download_url']);
        self::assertZipHasSingleFolderWithHeader($zipBytes, $slug, 'Theme Name');
        FileManagerService::extractZip('website', $site['domain'], 'wp-content/themes', $zipBytes, $userId);
        ActivityLog::record($userId, 'wp_manager.theme_install', "Instal tema {$slug} ({$info['version']}) di {$site['domain']} dari WordPress.org");
    }

    public static function installThemeByZip(array $site, string $zipBytes, ?int $userId): void
    {
        $folder = self::detectSingleFolderWithHeader($zipBytes, 'Theme Name');
        FileManagerService::extractZip('website', $site['domain'], 'wp-content/themes', $zipBytes, $userId);
        ActivityLog::record($userId, 'wp_manager.theme_install', "Instal tema {$folder} di {$site['domain']} dari upload ZIP");
    }

    /** @return array{latest_version:string,update_available:bool} */
    public static function checkThemeUpdate(string $folder, ?string $currentVersion): array
    {
        $info = self::fetchCatalogInfo('themes', $folder);
        return [
            'latest_version' => $info['version'],
            'update_available' => $currentVersion === null || version_compare($info['version'], $currentVersion, '>'),
        ];
    }

    public static function updateTheme(array $site, string $folder, ?int $userId): string
    {
        $info = self::fetchCatalogInfo('themes', $folder);
        $zipBytes = AppInstallerService::downloadFixedUrl($info['download_url']);
        self::assertZipHasSingleFolderWithHeader($zipBytes, $folder, 'Theme Name');

        BackupService::backupWebsite($site['domain'], $userId);

        FileManagerService::extractZip('website', $site['domain'], 'wp-content/themes', $zipBytes, $userId);
        ActivityLog::record($userId, 'wp_manager.theme_update', "Update tema {$folder} ke {$info['version']} di {$site['domain']}");
        return $info['version'];
    }

    // -------------------------------------------------------------------
    // WordPress.org plugin/theme directory API - fixed host, validated slug
    // -------------------------------------------------------------------

    /** @return array{version:string,download_url:string} */
    private static function fetchCatalogInfo(string $kind, string $slug): array
    {
        // $kind is always a literal 'plugins'/'themes' passed by this class
        // itself, never client input - only $slug (validated by the caller
        // before reaching here) varies.
        if (!Validator::wpSlug($slug)) {
            throw new InvalidArgumentException('Slug tidak valid');
        }
        $url = "https://api.wordpress.org/{$kind}/info/1.0/{$slug}.json";
        $body = AppInstallerService::fetchTextUrl($url, 65536);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['download_link']) || empty($data['version'])) {
            throw new RuntimeException("Tidak ditemukan di direktori resmi WordPress.org: {$slug}");
        }
        $downloadUrl = (string) $data['download_link'];
        if (!str_starts_with($downloadUrl, 'https://downloads.wordpress.org/')) {
            throw new RuntimeException('URL unduhan tidak valid');
        }
        return ['version' => (string) $data['version'], 'download_url' => $downloadUrl];
    }

    // -------------------------------------------------------------------
    // In-memory ZIP inspection helpers (ZipArchive) - same technique as
    // AppInstallerService::stripSingleRootDir, kept local since these checks
    // are specific to plugin/theme/core header conventions.
    // -------------------------------------------------------------------

    /** @return string[] distinct top-level folder names in the archive */
    private static function zipTopLevelFolders(string $zipBytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'yuuka_wpz_');
        if ($tmp === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk memproses ZIP');
        }
        try {
            file_put_contents($tmp, $zipBytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Gagal membuka ZIP');
            }
            $folders = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || $name === '') {
                    continue;
                }
                $slash = strpos($name, '/');
                if ($slash === false) {
                    $zip->close();
                    throw new RuntimeException('ZIP tidak valid: berisi file di root tanpa folder pembungkus');
                }
                $folders[substr($name, 0, $slash)] = true;
            }
            $zip->close();
            return array_keys($folders);
        } finally {
            @unlink($tmp);
        }
    }

    private static function zipContainsHeader(string $zipBytes, string $folder, string $headerKey): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'yuuka_wpz_');
        if ($tmp === false) {
            return false;
        }
        try {
            file_put_contents($tmp, $zipBytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                return false;
            }
            $suffix = $headerKey === 'Theme Name' ? 'style.css' : '.php';
            $found = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || !str_starts_with($name, $folder . '/') || !str_ends_with($name, $suffix)) {
                    continue;
                }
                // Only the folder's own top level, matching how WordPress
                // itself only looks at a plugin's own root (or a theme's
                // style.css) for these headers - not nested subdirectories.
                if (substr_count(substr($name, strlen($folder) + 1), '/') > 0) {
                    continue;
                }
                $content = $zip->getFromIndex($i);
                if ($content !== false && preg_match('/' . preg_quote($headerKey, '/') . ':\s*.+/i', substr($content, 0, 8192))) {
                    $found = true;
                    break;
                }
            }
            $zip->close();
            return $found;
        } finally {
            @unlink($tmp);
        }
    }

    /** For slug-based installs: the zip's single top folder must equal the requested slug and contain a valid header. */
    private static function assertZipHasSingleFolderWithHeader(string $zipBytes, string $expectedFolder, string $headerKey): void
    {
        $folders = self::zipTopLevelFolders($zipBytes);
        if ($folders !== [$expectedFolder]) {
            throw new RuntimeException('ZIP dari WordPress.org tidak sesuai format yang diharapkan');
        }
        if (!self::zipContainsHeader($zipBytes, $expectedFolder, $headerKey)) {
            throw new RuntimeException("ZIP tidak berisi header '{$headerKey}' yang valid");
        }
    }

    /** For admin-uploaded zips: detect (not assume) the single top folder, validating it has the expected header. */
    private static function detectSingleFolderWithHeader(string $zipBytes, string $headerKey): string
    {
        $folders = self::zipTopLevelFolders($zipBytes);
        if (count($folders) !== 1) {
            throw new InvalidArgumentException('ZIP harus berisi persis satu folder di root (folder plugin/tema itu sendiri)');
        }
        $folder = $folders[0];
        if (!self::zipContainsHeader($zipBytes, $folder, $headerKey)) {
            throw new InvalidArgumentException("ZIP tidak berisi header '{$headerKey}' yang valid di dalam folder {$folder}");
        }
        return $folder;
    }

    // -------------------------------------------------------------------
    // WordPress core update
    // -------------------------------------------------------------------

    /** @return array{latest_version:string,update_available:bool} */
    public static function checkCoreUpdate(array $site): array
    {
        $latest = WordpressInstallerService::resolveLatest();
        return [
            'latest_version' => $latest['version'],
            'update_available' => $site['app_version'] === null || version_compare($latest['version'], $site['app_version'], '>'),
        ];
    }

    /**
     * Downloads the official core zip, strips its wrapper folder, then
     * removes wp-content/* and wp-config-sample.php from it entirely so
     * the site's own themes/plugins/uploads/config can never be touched by
     * a core update - only wp-admin/wp-includes/root-level files come from
     * this zip.
     */
    private static function filterCoreZip(string $zipBytes): string
    {
        $zipBytes = AppInstallerService::stripSingleRootDir($zipBytes);

        $srcTmp = tempnam(sys_get_temp_dir(), 'yuuka_wpcore_');
        $dstTmp = tempnam(sys_get_temp_dir(), 'yuuka_wpcore_');
        if ($srcTmp === false || $dstTmp === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk memproses ZIP inti WordPress');
        }
        try {
            file_put_contents($srcTmp, $zipBytes);
            $zipIn = new ZipArchive();
            if ($zipIn->open($srcTmp) !== true) {
                throw new RuntimeException('Gagal membuka ZIP inti WordPress');
            }
            $zipOut = new ZipArchive();
            if ($zipOut->open($dstTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $zipIn->close();
                throw new RuntimeException('Gagal menyiapkan ZIP hasil filter');
            }

            for ($i = 0; $i < $zipIn->numFiles; $i++) {
                $name = $zipIn->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                if (str_starts_with($name, 'wp-content/') || $name === 'wp-config-sample.php') {
                    continue;
                }
                if (str_ends_with($name, '/')) {
                    $zipOut->addEmptyDir($name);
                    continue;
                }
                $contents = $zipIn->getFromIndex($i);
                if ($contents === false) {
                    continue;
                }
                $zipOut->addFromString($name, $contents);
            }
            $zipIn->close();
            $zipOut->close();

            $result = file_get_contents($dstTmp);
            if ($result === false) {
                throw new RuntimeException('Gagal membaca ZIP hasil filter');
            }
            return $result;
        } finally {
            @unlink($srcTmp);
            @unlink($dstTmp);
        }
    }

    /**
     * Verified BEFORE any destructive Executor call is made (deleting the
     * site's existing wp-admin/wp-includes) - catches a corrupt/truncated
     * download or an unexpected zip shape while it is still just bytes in
     * memory, not yet touching disk.
     */
    private static function assertCoreZipIntegrity(string $zipBytes, string $expectedVersion): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'yuuka_wpcore_chk_');
        if ($tmp === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk verifikasi ZIP');
        }
        try {
            file_put_contents($tmp, $zipBytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('ZIP inti WordPress hasil filter tidak valid');
            }
            $hasAdmin = $zip->locateName('wp-admin/index.php') !== false;
            $versionPhp = $zip->getFromName('wp-includes/version.php');
            $zip->close();

            if (!$hasAdmin || $versionPhp === false) {
                throw new RuntimeException('ZIP inti WordPress tidak lengkap - update dibatalkan sebelum mengubah apapun di disk');
            }
            if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $versionPhp, $m) && $m[1] !== $expectedVersion) {
                throw new RuntimeException("Versi di dalam ZIP ({$m[1]}) tidak cocok dengan versi yang diharapkan ({$expectedVersion}) - update dibatalkan");
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Full update flow: download -> filter -> verify (in memory) -> MANDATORY
     * backup (website + db, never skipped for this operation) -> delete old
     * wp-admin/wp-includes -> extract filtered zip -> update cached version.
     * The delete-then-extract window cannot be made atomic with the
     * primitives panel-exec.sh exposes (no cross-directory move); the
     * in-memory verification above removes the most likely failure cause,
     * the mandatory backup is the actual safety net for the rest.
     *
     * @return array{version:string}
     */
    public static function updateCore(array $site, ?int $userId): array
    {
        $latest = WordpressInstallerService::resolveLatest();
        $zipBytes = AppInstallerService::downloadFixedUrl($latest['download_url']);
        $zipBytes = self::filterCoreZip($zipBytes);
        self::assertCoreZipIntegrity($zipBytes, $latest['version']);

        BackupService::backupWebsite($site['domain'], $userId);
        if (!empty($site['db_name'])) {
            BackupService::backupDatabase($site['db_name'], $userId);
        }

        FileManagerService::delete('website', $site['domain'], 'public/wp-admin', $userId);
        FileManagerService::delete('website', $site['domain'], 'public/wp-includes', $userId);
        FileManagerService::extractZip('website', $site['domain'], 'public', $zipBytes, $userId);

        Database::app()->prepare('UPDATE installed_apps SET app_version = :v WHERE website_id = :id')
            ->execute(['v' => $latest['version'], 'id' => $site['website_id']]);

        ActivityLog::record($userId, 'wp_manager.core_update', "Update WordPress core ke {$latest['version']} di {$site['domain']}");

        return ['version' => $latest['version']];
    }
}
