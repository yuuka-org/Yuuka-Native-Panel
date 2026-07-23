<?php
declare(strict_types=1);

/**
 * The ONLY class in the panel allowed to invoke an external process for
 * privileged operations. It always calls the fixed, root-owned
 * panel-exec.sh through sudo, using proc_open's array-form command (no
 * shell interpolation is ever involved - argv is passed directly to
 * execve()), so arbitrary bytes in an argument can never be interpreted
 * as shell syntax.
 *
 * Callers (Service classes) are still responsible for validating input
 * with Validator BEFORE calling here - this class enforces a subcommand
 * whitelist as a second line of defense, but does not re-validate the
 * shape of every argument (that is Validator's job).
 */
final class Executor
{
    private const WHITELIST = [
        'nginx-test', 'nginx-reload', 'nginx-write-config', 'nginx-enable',
        'nginx-disable', 'nginx-delete',
        'pm2-deploy', 'pm2-start', 'pm2-stop', 'pm2-restart', 'pm2-reload', 'pm2-delete',
        'pm2-jlist', 'pm2-describe', 'pm2-logs', 'pm2-flush', 'pm2-save',
        'certbot-issue', 'certbot-remove',
        'service-status', 'service-restart',
        'installer-version-info', 'installer-check-update',
        'installer-self-update', 'installer-self-update-status',
        'mysqldump-db', 'mysql-restore-db',
        'cloudflared-status', 'cloudflared-restart', 'cloudflared-stop', 'cloudflared-start', 'cloudflared-version',
        'disk-usage',
        'fs-mkdir-website', 'fs-remove-website', 'fs-remove-nodeapp',
        'port-check',
        'files-list', 'files-read', 'files-write', 'files-mkdir', 'files-delete',
        'files-rename', 'files-extract-zip',
        'backup-tar-website', 'backup-tar-nodeapp', 'restore-tar-website', 'restore-tar-nodeapp',
        'cron-write', 'cron-delete',
        'log-tail', 'log-clear',
    ];

    /**
     * Subcommands whose successful stdout must be returned byte-for-byte
     * (no trim()) because it can be arbitrary binary content (file
     * downloads / file content for in-browser editing). trim() strips
     * leading/trailing whitespace-class bytes including NUL, which would
     * silently corrupt binary output.
     */
    private const RAW_OUTPUT_SUBCOMMANDS = ['files-read'];

    /**
     * @param string[] $args
     * @return array{ok: bool, exitCode: int, output: string}
     */
    public static function run(string $subcommand, array $args = [], ?string $stdin = null, int $timeoutSeconds = 30): array
    {
        if (!in_array($subcommand, self::WHITELIST, true)) {
            self::log($subcommand, false, 'rejected: not in whitelist');
            throw new InvalidArgumentException("Subcommand tidak diizinkan: {$subcommand}");
        }

        $script = Config::get('PANEL_EXEC_SCRIPT', '/opt/server-panel/scripts/panel-exec.sh');
        $command = array_merge(['sudo', '-n', $script, $subcommand], $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            self::log($subcommand, false, 'proc_open failed');
            return ['ok' => false, 'exitCode' => -1, 'output' => 'Gagal menjalankan proses sistem'];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], $timeoutSeconds);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $ok = $exitCode === 0;

        self::log($subcommand, $ok, "exit={$exitCode}");

        $raw = in_array($subcommand, self::RAW_OUTPUT_SUBCOMMANDS, true);

        return [
            'ok' => $ok,
            'exitCode' => $exitCode,
            'output' => $ok
                ? ($raw ? $stdout : trim($stdout))
                : trim($stderr !== '' ? $stderr : $stdout),
        ];
    }

    private static function log(string $subcommand, bool $ok, string $note): void
    {
        $line = sprintf(
            "[%s] subcommand=%s status=%s note=%s\n",
            date('c'),
            $subcommand,
            $ok ? 'ok' : 'fail',
            $note
        );
        @file_put_contents(LOG_PATH . '/executor.log', $line, FILE_APPEND);
    }
}
