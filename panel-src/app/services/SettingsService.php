<?php
declare(strict_types=1);

/** Simple key/value panel settings (table `settings`) - used across all Settings sub-tabs, dashboard.php's Alarm banner, and login.php's Page customization. */
final class SettingsService
{
    /**
     * Every key this codebase ever writes to `settings` - seeded ones from
     * sql/schema.sql (`deployment_mode`, the 3 alert thresholds), ones
     * written by install.sh/update.sh itself (`php_installed_versions`,
     * `php_default_version`), the UI-managed ones (`phpmyadmin_url`,
     * `panel_login_title`, `panel_login_logo`). Settings > Migrate's
     * import rejects anything not in this list - the table has no dynamic
     * behavior tied to arbitrary keys, but there's no reason to let an
     * imported file seed rows nothing here will ever read.
     */
    public const KNOWN_KEYS = [
        'deployment_mode',
        'cpu_alert_threshold',
        'mem_alert_threshold',
        'restart_alert_threshold',
        'phpmyadmin_url',
        'php_installed_versions',
        'php_default_version',
        'panel_login_title',
        'panel_login_logo',
    ];

    public static function get(string $key, string $default = ''): string
    {
        $stmt = Database::app()->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::app()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2'
        );
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    }

    /** @return array<string,string> all rows as setting_key => setting_value, for Settings > Migrate export. */
    public static function allKeys(): array
    {
        $rows = Database::app()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $out;
    }
}
