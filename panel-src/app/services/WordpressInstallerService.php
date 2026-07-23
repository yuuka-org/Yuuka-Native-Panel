<?php
declare(strict_types=1);

/**
 * WordPress-specific pieces of App Installer: resolving the current stable
 * release via the official version-check API, fetching fresh secret-key
 * salts, and generating a ready-to-use wp-config.php.
 */
final class WordpressInstallerService
{
    /**
     * Version-check only, no download - lets a caller (WP Manager's "Cek
     * update" button) find out whether a newer core version exists without
     * paying for a multi-MB download just to compare version strings.
     *
     * @return array{version:string,download_url:string}
     */
    public static function resolveLatest(): array
    {
        $app = AppCatalog::get('wordpress');
        $body = AppInstallerService::fetchTextUrl($app['version_check_url'], 65536);

        // api.wordpress.org/core/version-check/1.7/ responds with JSON
        // (verified directly against the live endpoint), not PHP-serialized
        // data - do not switch this to unserialize().
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['offers'][0]['download']) || empty($data['offers'][0]['version'])) {
            throw new RuntimeException('Gagal membaca informasi versi WordPress terbaru');
        }

        $downloadUrl = (string) $data['offers'][0]['download'];
        if (!str_starts_with($downloadUrl, 'https://')) {
            // Belt-and-suspenders even though this came from the official
            // API rather than user input.
            throw new RuntimeException('URL unduhan WordPress tidak valid');
        }

        return ['version' => (string) $data['offers'][0]['version'], 'download_url' => $downloadUrl];
    }

    /** @return array{bytes:string,version:string} */
    public static function downloadLatest(): array
    {
        $latest = self::resolveLatest();
        $bytes = AppInstallerService::downloadFixedUrl($latest['download_url']);
        return ['bytes' => $bytes, 'version' => $latest['version']];
    }

    /**
     * Downloads a specific (admin-chosen) WordPress version instead of
     * "latest". $version is client-supplied, so this is the one deliberate,
     * narrowly-scoped exception to "every URL is a literal constant" (see
     * AppCatalog's class doc): wordpress.org's release-archive naming
     * (wordpress-X.Y.Z.zip on the fixed host wordpress.org) has been
     * stable for over a decade, and the regex below only ever allows
     * digits and dots - no '/', '@', ':', or scheme, so it cannot redirect
     * the request to a different host or path no matter what an attacker
     * passes in. If validation fails this throws rather than silently
     * falling back to "latest", so a typo never surprises the admin with
     * the wrong version being installed.
     *
     * @return array{bytes:string,version:string}
     */
    public static function downloadSpecificVersion(string $version): array
    {
        if (!preg_match('/^\d{1,2}\.\d{1,2}(\.\d{1,3})?$/', $version)) {
            throw new InvalidArgumentException('Format versi WordPress tidak valid (contoh: 6.4.5)');
        }

        $url = "https://wordpress.org/wordpress-{$version}.zip";
        $bytes = AppInstallerService::downloadFixedUrl($url);
        return ['bytes' => $bytes, 'version' => $version];
    }

    public static function fetchSalts(): string
    {
        $app = AppCatalog::get('wordpress');
        $salts = AppInstallerService::fetchTextUrl($app['salt_api_url'], 16384);
        if (!str_contains($salts, 'AUTH_KEY')) {
            throw new RuntimeException('Gagal mengambil salt keys WordPress');
        }
        return $salts;
    }

    /**
     * Generates a random table prefix for a new install - a plain string,
     * not a secret (purely to avoid every WordPress site on the server
     * sharing identical table names by coincidence). Split out from
     * buildConfig() so the caller (AppInstallerService) can persist the
     * same value into installed_apps.table_prefix - WP Manager needs to
     * query {prefix}options later and previously had no way to recover
     * this value except by re-parsing wp-config.php.
     */
    public static function generateTablePrefix(): string
    {
        return 'wp_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_';
    }

    public static function buildConfig(string $dbName, string $dbUser, string $dbPassword, string $prefix, string $saltsBlock): string
    {
        // var_export() on every dynamic value is what makes this safe
        // against breaking out of the PHP string literal regardless of
        // what characters end up in the (server-generated) password.
        return "<?php\n"
            . 'define(\'DB_NAME\', ' . var_export($dbName, true) . ");\n"
            . 'define(\'DB_USER\', ' . var_export($dbUser, true) . ");\n"
            . 'define(\'DB_PASSWORD\', ' . var_export($dbPassword, true) . ");\n"
            . 'define(\'DB_HOST\', ' . var_export('127.0.0.1', true) . ");\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n\n"
            . $saltsBlock . "\n\n"
            . '$table_prefix = ' . var_export($prefix, true) . ";\n\n"
            . "define('WP_DEBUG', false);\n\n"
            . "if (!defined('ABSPATH')) {\n"
            . "    define('ABSPATH', __DIR__ . '/');\n"
            . "}\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";
    }
}
