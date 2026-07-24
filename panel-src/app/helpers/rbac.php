<?php
declare(strict_types=1);

/**
 * Role-Based Access Control. Every controller action that changes state or
 * reveals sensitive data must call Rbac::require() before proceeding.
 */
final class Rbac
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_VIEWER = 'viewer';

    /** @var array<string, array<string>> permission => roles allowed */
    private static array $matrix = [
        'server.manage_configuration'  => [self::ROLE_ADMIN],
        'terminal.access'               => [self::ROLE_ADMIN],
        'users.manage'                 => [self::ROLE_ADMIN],
        'settings.manage'               => [self::ROLE_ADMIN],
        'cloudflare.manage'            => [self::ROLE_ADMIN],

        'website.create'               => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'website.delete'                => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'website.toggle'                => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'website.view'                  => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'apps.install'                  => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'apps.view'                     => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'wp.manage'                     => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'wp.view'                       => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'nodejs.create'                 => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],
        'nodejs.delete'                 => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'nodejs.control'                => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],
        'nodejs.env.manage'             => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],
        'nodejs.view'                    => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],
        'nodejs.logs.view'              => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],

        'database.manage'               => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'database.view'                 => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'domain.manage'                 => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'ssl.manage'                     => [self::ROLE_ADMIN, self::ROLE_OPERATOR],

        'backup.manage'                 => [self::ROLE_ADMIN, self::ROLE_OPERATOR],
        'backup.view'                   => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'cron.manage'                    => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],
        'cron.view'                      => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'files.manage'                   => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],
        'files.view'                     => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],

        'logs.view'                      => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER],
        'monitoring.view'               => [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_DEVELOPER, self::ROLE_VIEWER],
    ];

    public static function can(string $role, string $permission): bool
    {
        if ($role === self::ROLE_ADMIN) {
            return true;
        }
        return in_array($role, self::$matrix[$permission] ?? [], true);
    }

    public static function require(string $permission): void
    {
        $user = Auth::user();
        if ($user === null) {
            redirect('/login.php');
        }
        if (!self::can($user['role'], $permission)) {
            http_response_code(403);
            flash('error', 'Anda tidak memiliki izin untuk melakukan aksi ini.');
            redirect('/dashboard.php');
        }
    }
}
