<?php
declare(strict_types=1);

/**
 * Low-level, read-only system statistics. These read directly from /proc
 * and PHP's own runtime (no shell invocation, no privilege needed) - the
 * one exception is service status, which is routed through Executor for
 * a consistent, audited privilege boundary even though the underlying
 * `systemctl is-active` query itself does not require root.
 */

function sys_cpu_usage_percent(): float
{
    $read = static function (): ?array {
        $line = @file('/proc/stat')[0] ?? null;
        if ($line === null) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts); // remove "cpu"
        return array_map('intval', $parts);
    };

    $first = $read();
    if ($first === null) {
        return 0.0;
    }
    usleep(150000);
    $second = $read();
    if ($second === null) {
        return 0.0;
    }

    $idle1 = $first[3] + ($first[4] ?? 0);
    $idle2 = $second[3] + ($second[4] ?? 0);
    $total1 = array_sum($first);
    $total2 = array_sum($second);

    $totalDelta = $total2 - $total1;
    $idleDelta = $idle2 - $idle1;

    if ($totalDelta <= 0) {
        return 0.0;
    }
    return round((1 - $idleDelta / $totalDelta) * 100, 1);
}

/** @return array{total_mb:int, used_mb:int, free_mb:int, percent:float} */
function sys_ram_usage(): array
{
    $meminfo = @file('/proc/meminfo');
    if ($meminfo === false) {
        return ['total_mb' => 0, 'used_mb' => 0, 'free_mb' => 0, 'percent' => 0.0];
    }

    $values = [];
    foreach ($meminfo as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $values[$m[1]] = (int) $m[2];
        }
    }

    $totalKb = $values['MemTotal'] ?? 0;
    $availableKb = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
    $usedKb = max(0, $totalKb - $availableKb);

    return [
        'total_mb' => intdiv($totalKb, 1024),
        'used_mb' => intdiv($usedKb, 1024),
        'free_mb' => intdiv($availableKb, 1024),
        'percent' => $totalKb > 0 ? round($usedKb / $totalKb * 100, 1) : 0.0,
    ];
}

/**
 * Reads root filesystem usage via Executor (`df`) rather than PHP's
 * disk_total_space()/disk_free_space(), because the panel PHP-FPM pool's
 * open_basedir does not include '/' (see modules/panel.sh).
 *
 * @return array{total_gb:float, used_gb:float, free_gb:float, percent:float}
 */
function sys_disk_usage(): array
{
    $result = Executor::run('disk-usage', [], null, 10);
    if (!$result['ok']) {
        return ['total_gb' => 0.0, 'used_gb' => 0.0, 'free_gb' => 0.0, 'percent' => 0.0];
    }

    $parts = preg_split('/\s+/', trim($result['output']));
    $total = (float) ($parts[0] ?? 0);
    $used = (float) ($parts[1] ?? 0);
    $avail = (float) ($parts[2] ?? 0);

    return [
        'total_gb' => round($total / 1073741824, 1),
        'used_gb' => round($used / 1073741824, 1),
        'free_gb' => round($avail / 1073741824, 1),
        'percent' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
    ];
}

/** @return array{0:float,1:float,2:float} 1/5/15 minute load average */
function sys_load_average(): array
{
    $load = sys_getloadavg();
    return $load !== false ? $load : [0.0, 0.0, 0.0];
}

function sys_uptime_seconds(): int
{
    $content = @file_get_contents('/proc/uptime');
    if ($content === false) {
        return 0;
    }
    return (int) explode(' ', trim($content))[0];
}

function sys_uptime_human(): string
{
    $seconds = sys_uptime_seconds();
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return "{$days}h {$hours}j {$minutes}m";
}

/** Distro name (e.g. "Ubuntu 22.04.4 LTS") - read once per page load, not part of the 5s AJAX refresh. */
function sys_os_pretty_name(): string
{
    $lines = @file('/etc/os-release');
    if ($lines !== false) {
        foreach ($lines as $line) {
            if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/', $line, $m)) {
                return trim($m[1]);
            }
        }
    }
    return php_uname('s');
}

function sys_kernel_version(): string
{
    return php_uname('r');
}

function sys_service_status(string $serviceName): string
{
    if (!Validator::serviceName($serviceName)) {
        return 'unknown';
    }
    $result = Executor::run('service-status', [$serviceName], null, 10);
    $status = strtolower(trim($result['output']));
    return in_array($status, ['active', 'inactive', 'failed', 'activating'], true) ? $status : 'unknown';
}
