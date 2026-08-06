<?php
declare(strict_types=1);

/**
 * Invoked every minute by system cron (as the 'panel' user - see
 * modules/panel.sh's module_panel_backup_scheduler_cron) to run any
 * backup schedule (Settings > Backup > Jadwal Backup) whose interval has
 * elapsed since it last ran. Same "cheap per-minute poll, skip if not
 * due" pattern as health_check_runner.php.
 */

require __DIR__ . '/../app/config/config.php';
Config::load(__DIR__ . '/../.env');

define('LOG_PATH', Config::get('LOG_PATH', __DIR__ . '/../storage/logs'));

require __DIR__ . '/../app/config/database.php';
foreach (glob(__DIR__ . '/../app/helpers/*.php') as $helperFile) {
    require $helperFile;
}
foreach (glob(__DIR__ . '/../app/scripts/*.php') as $scriptFile) {
    require $scriptFile;
}
spl_autoload_register(function (string $class): void {
    foreach (['services', 'controllers'] as $dir) {
        $path = __DIR__ . "/../app/{$dir}/{$class}.php";
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

foreach (BackupScheduleService::dueSchedules() as $schedule) {
    $id = (int) $schedule['id'];
    $type = (string) $schedule['type'];
    $target = (string) $schedule['target_name'];

    try {
        match ($type) {
            'database' => BackupService::backupDatabase($target, null),
            'website' => BackupService::backupWebsite($target, null),
            'nodejs' => BackupService::backupNodeApp($target, null),
            default => throw new InvalidArgumentException("Tipe tidak dikenal: {$type}"),
        };
        BackupScheduleService::recordRun($id, 'completed');
        @file_put_contents(LOG_PATH . '/backup-scheduler.log', '[' . date('c') . "] OK: {$type}:{$target}\n", FILE_APPEND);
    } catch (Throwable $e) {
        BackupScheduleService::recordRun($id, 'failed');
        @file_put_contents(LOG_PATH . '/backup-scheduler.log', '[' . date('c') . "] GAGAL: {$type}:{$target} - {$e->getMessage()}\n", FILE_APPEND);
    }
}
