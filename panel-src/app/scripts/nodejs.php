<?php
declare(strict_types=1);

/**
 * Low-level PM2 wrappers. Every app started through the panel goes through
 * an ecosystem.config.js file (never an ad-hoc `pm2 start server.js`), so
 * instances/exec_mode/env/max_memory_restart are always explicit and
 * reproducible.
 */

/**
 * @param array<string,string> $env Plain (already-decrypted) env vars.
 *                                   Values are safely JSON-encoded, never
 *                                   interpolated into a shell command.
 */
function nodejs_build_ecosystem_config(
    string $pm2Name,
    string $cwd,
    string $script,
    int $instances,
    string $execMode,
    bool $autorestart,
    bool $watch,
    string $maxMemoryRestart,
    string $nodeEnv,
    array $env
): string {
    $envPayload = $env;
    $envPayload['NODE_ENV'] = $nodeEnv;

    $config = [
        'apps' => [[
            'name' => $pm2Name,
            'script' => $script,
            'cwd' => $cwd,
            'instances' => $instances,
            'exec_mode' => $execMode,
            'autorestart' => $autorestart,
            'watch' => $watch,
            'max_memory_restart' => $maxMemoryRestart,
            'env' => $envPayload,
        ]],
    ];

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return "module.exports = {$json};\n";
}

function nodejs_pm2_deploy(string $pm2Name, string $ecosystemContent, string $nodeVersion = '', ?string $buildCommand = null): array
{
    // Build commands (npm install/npm run build, etc.) can legitimately
    // take a while - a longer timeout here than other PM2 ops, matching
    // op_pm2_deploy's own build step running synchronously before `pm2
    // start`.
    return Executor::run('pm2-deploy', [$pm2Name, $nodeVersion, (string) $buildCommand], $ecosystemContent, 180);
}

function nodejs_pm2_start(string $pm2Name): array
{
    return Executor::run('pm2-start', [$pm2Name], null, 20);
}

function nodejs_pm2_stop(string $pm2Name): array
{
    return Executor::run('pm2-stop', [$pm2Name], null, 20);
}

function nodejs_pm2_restart(string $pm2Name): array
{
    return Executor::run('pm2-restart', [$pm2Name], null, 20);
}

function nodejs_pm2_reload(string $pm2Name): array
{
    return Executor::run('pm2-reload', [$pm2Name], null, 20);
}

function nodejs_pm2_delete(string $pm2Name): array
{
    return Executor::run('pm2-delete', [$pm2Name], null, 20);
}

function nodejs_pm2_describe(string $pm2Name): array
{
    return Executor::run('pm2-describe', [$pm2Name], null, 15);
}

function nodejs_pm2_logs(string $pm2Name, int $lines = 100): array
{
    $lines = max(1, min(1000, $lines));
    return Executor::run('pm2-logs', [$pm2Name, (string) $lines], null, 15);
}

function nodejs_pm2_flush(string $pm2Name): array
{
    return Executor::run('pm2-flush', [$pm2Name], null, 15);
}

function nodejs_pm2_save(): array
{
    return Executor::run('pm2-save', [], null, 15);
}

/**
 * The single source of truth for Node.js application runtime state.
 * @return array<int, array<string,mixed>> normalized process list
 */
function nodejs_pm2_jlist(): array
{
    $result = Executor::run('pm2-jlist', [], null, 15);
    if (!$result['ok']) {
        return [];
    }

    $decoded = json_decode($result['output'], true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $proc) {
        $env = $proc['pm2_env'] ?? [];
        $monit = $proc['monit'] ?? [];

        $normalized[] = [
            'pm2_id' => $proc['pm_id'] ?? null,
            'name' => $proc['name'] ?? 'unknown',
            'pid' => $proc['pid'] ?? null,
            'status' => $env['status'] ?? 'unknown',
            'cpu_percent' => $monit['cpu'] ?? 0,
            'memory_bytes' => $monit['memory'] ?? 0,
            'uptime_ms' => isset($env['pm_uptime']) ? (int) (microtime(true) * 1000) - (int) $env['pm_uptime'] : null,
            'restart_count' => $env['restart_time'] ?? 0,
            'unstable_restarts' => $env['unstable_restarts'] ?? 0,
            'exec_mode' => $env['exec_mode'] ?? null,
            'instances' => $env['instances'] ?? 1,
            'node_version' => $env['node_version'] ?? null,
            'pm2_version' => $env['version'] ?? null,
            'script_path' => $env['pm_exec_path'] ?? null,
            'cwd' => $env['pm_cwd'] ?? null,
            'out_log_path' => $env['pm_out_log_path'] ?? null,
            'error_log_path' => $env['pm_err_log_path'] ?? null,
            'created_at' => $env['created_at'] ?? null,
        ];
    }

    return $normalized;
}

function nodejs_pm2_status_map(): array
{
    $map = [];
    foreach (nodejs_pm2_jlist() as $proc) {
        $map[$proc['name']] = $proc;
    }
    return $map;
}
