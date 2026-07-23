<?php
declare(strict_types=1);

/**
 * Reads log output through the Executor (nginx/php-fpm logs are root/adm
 * owned) or directly for logs the panel process already owns (PM2 output
 * is fetched via NodeService/pm2-logs; panel's own app-error.log lives
 * under storage/logs which 'panel' owns).
 *
 * Secret values (env vars, passwords, tokens) never pass through here -
 * these are raw process/webserver logs, not application-level dumps of
 * panel data.
 */
final class LogService
{
    private const MAX_LINES = 2000;

    public static function nginxAccess(string $domain, int $lines = 200): string
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        return self::tail("nginx-access:{$domain}", $lines);
    }

    public static function nginxError(string $domain, int $lines = 200): string
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        return self::tail("nginx-error:{$domain}", $lines);
    }

    public static function phpFpmError(string $phpVersion, int $lines = 200): string
    {
        if (!PhpService::isValidVersion($phpVersion)) {
            throw new InvalidArgumentException('Versi PHP tidak valid');
        }
        return self::tail("phpfpm-error:{$phpVersion}", $lines);
    }

    public static function deploymentLog(int $lines = 200): string
    {
        return self::tail('deployment', $lines);
    }

    public static function selfUpdateLog(int $lines = 200): string
    {
        return self::tail('self-update', $lines);
    }

    public static function clearNginxAccess(string $domain): void
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        Executor::run('log-clear', ["nginx-access:{$domain}"], null, 10);
    }

    public static function clearNginxError(string $domain): void
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        Executor::run('log-clear', ["nginx-error:{$domain}"], null, 10);
    }

    public static function panelAppLog(int $lines = 200): string
    {
        $path = LOG_PATH . '/app-error.log';
        if (!is_file($path)) {
            return '';
        }
        $lines = max(1, min(self::MAX_LINES, $lines));
        $content = shell_command_free_tail($path, $lines);
        return $content;
    }

    private static function tail(string $logKey, int $lines): string
    {
        $lines = max(1, min(self::MAX_LINES, $lines));
        $result = Executor::run('log-tail', [$logKey, (string) $lines], null, 15);
        return $result['ok'] ? $result['output'] : '';
    }
}

/**
 * Pure-PHP tail implementation (no shell) for log files the panel process
 * already has direct filesystem access to.
 */
function shell_command_free_tail(string $path, int $lines): string
{
    $fileHandle = @fopen($path, 'r');
    if ($fileHandle === false) {
        return '';
    }

    $buffer = [];
    while (($line = fgets($fileHandle)) !== false) {
        $buffer[] = $line;
        if (count($buffer) > $lines) {
            array_shift($buffer);
        }
    }
    fclose($fileHandle);

    return implode('', $buffer);
}
