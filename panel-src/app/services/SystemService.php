<?php
declare(strict_types=1);

final class SystemService
{
    public static function summary(): array
    {
        return [
            'cpu_percent' => sys_cpu_usage_percent(),
            'ram' => sys_ram_usage(),
            'disk' => sys_disk_usage(),
            'load' => sys_load_average(),
            'uptime' => sys_uptime_human(),
            'uptime_seconds' => sys_uptime_seconds(),
        ];
    }

    /**
     * Static-ish server identity info (OS, kernel, hostname) - fetched once
     * per page load, unlike summary() which is polled every 5s by the
     * dashboard's AJAX refresh. None of this changes without a reboot.
     *
     * @return array{os:string,kernel:string,hostname:string,php_version:string}
     */
    public static function serverInfo(): array
    {
        return [
            'os' => sys_os_pretty_name(),
            'kernel' => sys_kernel_version(),
            'hostname' => gethostname() ?: 'unknown',
            'php_version' => PHP_VERSION,
        ];
    }

    /** @return array<string,string> serviceName => active|inactive|failed|unknown */
    public static function serviceStatuses(): array
    {
        $services = ['nginx', 'mariadb', 'cloudflared'];
        foreach (PhpService::installedVersions() as $v) {
            $services[] = "php{$v}-fpm";
        }

        $statuses = [];
        foreach ($services as $svc) {
            $statuses[$svc] = sys_service_status($svc);
        }
        return $statuses;
    }

    public static function nodejsRunningCount(): int
    {
        $count = 0;
        foreach (nodejs_pm2_jlist() as $proc) {
            if ($proc['status'] === 'online') {
                $count++;
            }
        }
        return $count;
    }
}
