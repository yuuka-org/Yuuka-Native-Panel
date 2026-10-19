<?php
declare(strict_types=1);

/**
 * Fixed catalog of applications installable via App Installer. Data only -
 * no logic here. This is the whole SSRF answer for the feature: the
 * controller/service only ever accepts an `app_slug` (and, for multi-
 * version apps, an `app_version` matched against this array's own
 * 'versions' list) - it NEVER accepts a URL from the client, and never
 * builds one from client-supplied text. Every URL that ever reaches curl
 * is either a constant defined here, or (WordPress only - see
 * WordpressInstallerService::downloadSpecificVersion()) built from a
 * digits-and-dots-only version string validated against a strict regex
 * before it ever touches string interpolation.
 *
 * "partial" tier apps (joomla/drupal/phpbb) pin specific download URLs +
 * versions rather than resolving "latest" dynamically, because none of
 * them expose an API as simple/stable as WordPress's version-check
 * endpoint. Each 'versions' entry needs occasional manual bumping as new
 * releases ship - that is a one-line constant update, not an architecture
 * change. Verified against each project's official requirements/release
 * pages on 2026-07-23.
 */
final class AppCatalog
{
    public const TIER_FULL = 'full';
    public const TIER_PARTIAL = 'partial';
    public const TIER_GENERIC = 'generic';

    private const APPS = [
        'wordpress' => [
            'name' => 'WordPress',
            'icon' => 'bi-wordpress',
            'description' => 'CMS blog/website paling populer. Instalasi penuh otomatis - wp-config.php langsung siap pakai, tinggal buka wp-admin/install.php untuk buat user admin.',
            'tier' => self::TIER_FULL,
            'requires_database' => true,
            'version_check_url' => 'https://api.wordpress.org/core/version-check/1.7/',
            'salt_api_url' => 'https://api.wordpress.org/secret-key/1.1/salt/',
            // WordPress itself only requires PHP 7.2+ - every PHP version
            // this panel offers (7.4-8.4) already qualifies regardless of
            // which WordPress version is picked, so there is no
            // version-vs-PHP filtering to do here (unlike Joomla/Drupal).
            'php_min' => '7.2',
            'php_max' => null,
        ],
        'joomla' => [
            'name' => 'Joomla',
            'icon' => 'bi-globe',
            'description' => 'CMS Joomla. File diekstrak & database kosong dibuat - selesaikan instalasi lewat web installer bawaan Joomla.',
            'tier' => self::TIER_PARTIAL,
            'requires_database' => true,
            'versions' => [
                [
                    'version' => '6.1.2',
                    'label' => 'Joomla 6.x (Terbaru)',
                    'php_min' => '8.1',
                    'php_max' => null,
                    'download_url' => 'https://github.com/joomla/joomla-cms/releases/download/6.1.2/Joomla_6.1.2-Stable-Full_Package.zip',
                ],
                [
                    'version' => '5.4.7',
                    'label' => 'Joomla 5.x (LTS)',
                    'php_min' => '8.1',
                    'php_max' => '8.3',
                    'download_url' => 'https://github.com/joomla/joomla-cms/releases/download/5.4.7/Joomla_5.4.7-Stable-Full_Package.zip',
                ],
            ],
        ],
        'drupal' => [
            'name' => 'Drupal',
            'icon' => 'bi-droplet',
            'description' => 'CMS Drupal. File diekstrak & database kosong dibuat - selesaikan instalasi lewat web installer bawaan Drupal.',
            'tier' => self::TIER_PARTIAL,
            'requires_database' => true,
            'versions' => [
                [
                    'version' => '11.4.4',
                    'label' => 'Drupal 11.x (Terbaru)',
                    'php_min' => '8.3',
                    'php_max' => null,
                    'download_url' => 'https://ftp.drupal.org/files/projects/drupal-11.4.4.zip',
                ],
                [
                    'version' => '10.6.14',
                    'label' => 'Drupal 10.x (PHP lebih lama)',
                    'php_min' => '8.1',
                    'php_max' => '8.4',
                    'download_url' => 'https://ftp.drupal.org/files/projects/drupal-10.6.14.zip',
                ],
            ],
        ],
        'phpbb' => [
            'name' => 'phpBB',
            'icon' => 'bi-chat-square-text',
            'description' => 'Forum phpBB. File diekstrak & database kosong dibuat - selesaikan instalasi lewat web installer bawaan phpBB.',
            'tier' => self::TIER_PARTIAL,
            'requires_database' => true,
            // Only one branch (3.3.x) is currently maintained by the phpBB
            // team - the older 3.2.x is EOL/insecure and deliberately not
            // offered here, so there is genuinely just one entry.
            'versions' => [
                [
                    'version' => '3.3.17',
                    'label' => 'phpBB 3.3.x (Terbaru)',
                    'php_min' => '7.4',
                    'php_max' => null,
                    'download_url' => 'https://download.phpbb.com/pub/release/3.3/3.3.17/phpBB-3.3.17.zip',
                ],
            ],
        ],
        // runtime 'nodejs' (default untuk entri lain: PHP) - dipasang sebagai
        // app Node.js baru (PM2 + vhost reverse proxy), bukan website PHP,
        // lewat NineRouterInstallerService. Versi diambil dari npm registry
        // saat instal ("latest") kecuali admin mengisi versi spesifik -
        // sama seperti WordPress, tidak ada daftar versi yang perlu di-bump.
        '9router' => [
            'name' => '9Router',
            'icon' => 'bi-router',
            'description' => 'AI gateway: satu endpoint OpenAI-compatible (/v1) untuk Claude Code, Codex, Cursor, Cline dll, dengan fallback otomatis antar provider/akun. Dipasang sebagai aplikasi Node.js baru di domain sendiri, password dashboard dibuat otomatis.',
            'tier' => self::TIER_FULL,
            'runtime' => 'nodejs',
            'requires_database' => false,
            'npm_package' => '9router',
            'node_version' => '22',
            'start_script' => 'node_modules/9router/app/custom-server.js',
        ],
        'custom' => [
            'name' => 'Custom App (Upload ZIP Sendiri)',
            'icon' => 'bi-upload',
            'description' => 'Upload ZIP aplikasi PHP sendiri ke website baru. Tidak ada konfigurasi otomatis - diekstrak persis apa adanya.',
            'tier' => self::TIER_GENERIC,
            'requires_database' => false,
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::APPS;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $slug): ?array
    {
        return self::APPS[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::APPS[$slug]);
    }

    /** 'php' (default, ditanam ke website PHP baru) atau 'nodejs' (app Node.js baru). */
    public static function runtime(string $slug): string
    {
        return self::APPS[$slug]['runtime'] ?? 'php';
    }

    /** @return array<string,mixed>|null */
    public static function findVersion(string $slug, string $version): ?array
    {
        $app = self::get($slug);
        if ($app === null || empty($app['versions'])) {
            return null;
        }
        foreach ($app['versions'] as $entry) {
            if ($entry['version'] === $version) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * True if $phpVersion satisfies [$phpMin, $phpMax] (max may be null =
     * no upper bound). Used both to filter the "Versi PHP" dropdown in the
     * UI and to re-validate server-side, since the client-side filtering
     * is only a convenience and must never be trusted alone.
     */
    public static function phpCompatible(string $phpVersion, string $phpMin, ?string $phpMax): bool
    {
        if (version_compare($phpVersion, $phpMin, '<')) {
            return false;
        }
        if ($phpMax !== null && version_compare($phpVersion, $phpMax, '>')) {
            return false;
        }
        return true;
    }
}
