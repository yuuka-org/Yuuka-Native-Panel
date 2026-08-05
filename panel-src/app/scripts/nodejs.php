<?php
declare(strict_types=1);

/**
 * Low-level PM2 wrappers. Every app started through the panel goes through
 * an ecosystem.config.cjs file (never an ad-hoc `pm2 start server.js`), so
 * instances/exec_mode/env/max_memory_restart are always explicit and
 * reproducible. Always .cjs, not .js - panel-exec.sh's op_pm2_deploy()
 * writes it that way so it stays CommonJS regardless of whether the app's
 * own package.json declares "type": "module".
 */

/** Mirrors panel-exec.sh's PM2_LOG_DIR constant exactly - keep both in sync. */
const NODEJS_PM2_LOG_DIR = '/home/nodeapps/.pm2/logs';

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
            // Without this, cluster mode with >1 instances writes a
            // SEPARATE pair of log files per worker id (app-out-0.log,
            // app-out-1.log, ...) - `pm2 logs`/the panel's Logs tab
            // reasonably expect ONE combined stream per app, so whichever
            // worker happened to log last (or wasn't the one currently
            // being tailed) made logging look like it "sometimes doesn't
            // record" when it was actually landing in a different file
            // entirely. Irrelevant but harmless for fork mode/instances=1.
            'merge_logs' => true,
            // out_file AND error_file deliberately point at the SAME path
            // (not PM2's own default naming) - two things depend on this
            // exact convention matching panel-exec.sh's PM2_LOG_DIR/rotate_pm2_log():
            // (1) stdout+stderr end up interleaved in ONE file instead of
            // two, which is what the panel's single combined Logs view
            // shows; (2) the panel reads this file directly rather than
            // through `pm2 logs` (whose CLI output prefixes every line
            // with "<id>|<name> |", meant for watching several apps at
            // once in a real terminal - noise here since only one app is
            // ever shown at a time).
            'out_file' => NODEJS_PM2_LOG_DIR . "/{$pm2Name}.log",
            'error_file' => NODEJS_PM2_LOG_DIR . "/{$pm2Name}.log",
            'log_date_format' => 'YYYY-MM-DD HH:mm:ss Z',
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

function nodejs_pm2_logs_size(string $pm2Name): int
{
    $result = Executor::run('pm2-logs-size', [$pm2Name], null, 10);
    return $result['ok'] ? (int) trim($result['output']) : 0;
}

/**
 * Incremental read for the real-time log viewer - $sinceOffset is the
 * byte offset already shown to the client (0 on first call). Returns
 * ['offset' => int new offset to pass next time, 'content' => string new bytes].
 */
function nodejs_pm2_logs_tail(string $pm2Name, int $sinceOffset): array
{
    $result = Executor::run('pm2-logs-tail', [$pm2Name, (string) max(0, $sinceOffset)], null, 10);
    if (!$result['ok']) {
        return ['offset' => $sinceOffset, 'content' => ''];
    }
    $parts = explode("\n", $result['output'], 2);
    return [
        'offset' => (int) $parts[0],
        'content' => $parts[1] ?? '',
    ];
}

/** @return string[] archived run log filenames, newest first */
function nodejs_pm2_logs_list(string $pm2Name): array
{
    $result = Executor::run('pm2-logs-list', [$pm2Name], null, 10);
    if (!$result['ok'] || trim($result['output']) === '') {
        return [];
    }
    return array_values(array_filter(explode("\n", trim($result['output']))));
}

function nodejs_pm2_logs_read_archive(string $pm2Name, string $file, int $lines = 1000): array
{
    $lines = max(1, min(1000, $lines));
    return Executor::run('pm2-logs-read-archive', [$pm2Name, $file, (string) $lines], null, 15);
}

function nodejs_pm2_flush(string $pm2Name): array
{
    return Executor::run('pm2-flush', [$pm2Name], null, 15);
}

function nodejs_pm2_reset(string $pm2Name): array
{
    return Executor::run('pm2-reset', [$pm2Name], null, 15);
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
