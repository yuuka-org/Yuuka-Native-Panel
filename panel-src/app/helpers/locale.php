<?php
declare(strict_types=1);

/**
 * Minimal i18n - t('group.key') resolves against app/lang/<locale>.php.
 * Locale resolution order: per-session override (the language switcher
 * in the topbar) -> panel-wide default (Settings > General, admin-set)
 * -> 'id' (this panel's original language, always available as the last
 * resort). A key missing in the active locale falls back to English,
 * and a key missing everywhere falls back to the raw key string itself
 * - a missing translation should degrade to something visibly
 * wrong-but-functional (e.g. "sidebar.website" printed literally), never
 * a fatal error or a blank label.
 *
 * Deliberately plain PHP arrays, not gettext/.po or a Composer i18n
 * library - this codebase has no build step and no Composer dependency
 * at all (see wiki/Arsitektur.md), and a `require`'d PHP array is both
 * the simplest and the fastest option available without either.
 *
 * Named PanelLocale, NOT Locale - `Locale` is a REAL built-in PHP class
 * from the intl extension (Locale::getDefault() etc). Declaring our own
 * `class Locale` fatally crashed EVERY page on a server that has intl
 * enabled ("Cannot redeclare class Locale") - intl ships with most
 * distro PHP-FPM packages and is common enough that this must never be
 * reused as a class name here again.
 */
final class PanelLocale
{
    /** Every locale a language file actually exists for - the only valid values for Settings > General's dropdown and the topbar switcher. */
    public const AVAILABLE = ['id', 'en'];
    private const FALLBACK = 'en';

    private static ?array $strings = null;
    private static ?array $fallbackStrings = null;
    private static ?string $current = null;

    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $fromSession = $_SESSION['locale'] ?? null;
        if (is_string($fromSession) && in_array($fromSession, self::AVAILABLE, true)) {
            return self::$current = $fromSession;
        }
        $default = SettingsService::get('default_locale');
        if ($default !== '' && in_array($default, self::AVAILABLE, true)) {
            return self::$current = $default;
        }
        return self::$current = 'id';
    }

    /** Session-only override (the topbar switcher) - Settings > General's dropdown writes the panel-wide default instead, via SettingsService::set() directly. */
    public static function setSessionLocale(string $locale): bool
    {
        if (!in_array($locale, self::AVAILABLE, true)) {
            return false;
        }
        $_SESSION['locale'] = $locale;
        self::$current = $locale;
        self::$strings = null;
        return true;
    }

    public static function get(string $key, array $params = []): string
    {
        $value = self::lookup(self::strings(), $key) ?? self::lookup(self::fallbackStrings(), $key) ?? $key;
        if (empty($params)) {
            return $value;
        }
        $replace = [];
        foreach ($params as $k => $v) {
            $replace['{' . $k . '}'] = (string) $v;
        }
        return strtr($value, $replace);
    }

    private static function lookup(array $strings, string $key): ?string
    {
        $current = $strings;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return is_string($current) ? $current : null;
    }

    private static function strings(): array
    {
        if (self::$strings === null) {
            self::$strings = self::load(self::current());
        }
        return self::$strings;
    }

    private static function fallbackStrings(): array
    {
        if (self::$fallbackStrings === null) {
            self::$fallbackStrings = self::load(self::FALLBACK);
        }
        return self::$fallbackStrings;
    }

    private static function load(string $locale): array
    {
        $path = APP_PATH . "/app/lang/{$locale}.php";
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }
}

/**
 * @param array<string,string|int> $params {name}-style placeholders substituted into the resolved string
 */
function t(string $key, array $params = []): string
{
    return PanelLocale::get($key, $params);
}
