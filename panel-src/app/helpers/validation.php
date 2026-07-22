<?php
declare(strict_types=1);

/**
 * Centralized input validators. Every value that eventually reaches
 * Executor::run() (and therefore panel-exec.sh / PM2 / Nginx / MySQL) MUST
 * pass through here first. Validation here is defense-in-depth alongside
 * the identical regexes enforced again inside panel-exec.sh itself.
 */
final class Validator
{
    public static function domain(string $value): bool
    {
        return (bool) preg_match(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/',
            $value
        ) && strlen($value) <= 190;
    }

    public static function email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function appName(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value);
    }

    public static function dbName(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value);
    }

    public static function dbUser(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{1,32}$/', $value);
    }

    public static function username(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $value);
    }

    public static function port(int $value, int $min = 1024, int $max = 65535): bool
    {
        return $value >= $min && $value <= $max;
    }

    public static function phpVersion(string $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    public static function nodeVersion(string $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    public static function absolutePathWithin(string $path, string $base): bool
    {
        $real = realpath($path) ?: $path;
        $realBase = realpath($base) ?: $base;
        return str_starts_with($real, rtrim($realBase, '/') . '/') || $real === $realBase;
    }

    /** Environment variable names: uppercase, digits, underscore - shell/PM2 safe */
    public static function envVarName(string $value): bool
    {
        return (bool) preg_match('/^[A-Z_][A-Z0-9_]{0,127}$/', $value);
    }

    public static function envVarValue(string $value): bool
    {
        // Disallow raw newlines/NUL which could break the ecosystem.config.js
        // serialization; the value itself is always emitted as a JSON string,
        // never interpolated into a shell command.
        return !str_contains($value, "\0") && strlen($value) <= 8192;
    }

    public static function cronSchedule(string $value): bool
    {
        // Standard 5-field cron expression (minute hour day month weekday),
        // permissive charset but no shell metacharacters whatsoever.
        $fields = preg_split('/\s+/', trim($value));
        if ($fields === false || count($fields) !== 5) {
            return false;
        }
        foreach ($fields as $field) {
            if (!preg_match('/^[0-9*\/,-]{1,32}$/', $field)) {
                return false;
            }
        }
        return true;
    }

    public static function relativeScriptPath(string $value): bool
    {
        if (str_contains($value, '..') || str_starts_with($value, '/')) {
            return false;
        }
        return (bool) preg_match('#^[a-zA-Z0-9_./-]{1,255}$#', $value);
    }

    /**
     * A relative path INSIDE a File Manager scope (website document root or
     * node app project dir). Unlike relativeScriptPath(), this allows
     * spaces/unicode/most punctuation in real-world filenames - the actual
     * escape-prevention guarantee comes from realpath containment
     * (absolutePathWithin()) applied to the resolved path both here and
     * again inside panel-exec.sh, not from a strict charset whitelist.
     * Empty string is valid and means "scope root".
     */
    public static function relativeFilePath(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        if (str_contains($value, "\0") || str_contains($value, '..') || str_starts_with($value, '/')) {
            return false;
        }
        return strlen($value) <= 4096;
    }

    /**
     * A single path segment (filename/foldername only, no directory
     * separators) - used for "new folder name" and rename targets, which
     * are intentionally restricted to same-directory renames in v1.
     */
    public static function fileBaseName(string $value): bool
    {
        if ($value === '' || $value === '.' || $value === '..') {
            return false;
        }
        if (str_contains($value, "\0") || str_contains($value, '/')) {
            return false;
        }
        return strlen($value) <= 255;
    }

    /** website | nodeapp - the two File Manager scopes */
    public static function fileManagerScope(string $value): bool
    {
        return in_array($value, ['website', 'nodeapp'], true);
    }

    public static function sitename(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9._-]{1,200}$/', $value);
    }

    public static function serviceName(string $value): bool
    {
        static $whitelist = [
            'nginx', 'mariadb', 'cloudflared',
            'php7.4-fpm', 'php8.0-fpm', 'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm', 'php8.4-fpm',
        ];
        return in_array($value, $whitelist, true);
    }

    public static function healthCheckUrl(string $value): bool
    {
        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        return in_array($parts['scheme'], ['http', 'https'], true);
    }
}
