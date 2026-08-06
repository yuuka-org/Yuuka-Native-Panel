<?php
declare(strict_types=1);

/**
 * Recurring automatic backups (Settings > Backup > Jadwal Backup). The
 * actual trigger is a single fixed system cron entry
 * (/etc/cron.d/panel-backup-scheduler, provisioned by
 * modules/panel.sh's module_panel_backup_scheduler_cron - see 'yp repair
 * panel'/update.sh) that invokes backup_scheduler_runner.php every
 * minute; that script calls dueSchedules() and runs whatever's due. Due-
 * ness is computed here in PHP (elapsed seconds since last_run_at) rather
 * than approximated with cron step syntax, because standard cron has no
 * year field at all and step syntax ("every N") on day-of-month divides
 * unevenly across months of different lengths - one exact mechanism
 * covers every unit.
 */
final class BackupScheduleService
{
    private const ALLOWED_TYPES = ['database', 'website', 'nodejs'];
    private const ALLOWED_UNITS = ['minute', 'hour', 'day', 'month', 'year'];

    /** @return array<int,array<string,mixed>> */
    public static function listAll(): array
    {
        return Database::app()->query('SELECT * FROM backup_schedules ORDER BY created_at DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM backup_schedules WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $type, string $targetName, string $intervalUnit, int $intervalValue, ?int $userId): array
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Tipe backup tidak dikenal');
        }
        if (!in_array($intervalUnit, self::ALLOWED_UNITS, true)) {
            throw new InvalidArgumentException('Satuan interval tidak dikenal');
        }
        if ($intervalValue < 1 || $intervalValue > 1000) {
            throw new InvalidArgumentException('Nilai interval harus 1-1000');
        }
        self::assertTargetExists($type, $targetName);

        $pdo = Database::app();
        $dup = $pdo->prepare('SELECT COUNT(*) FROM backup_schedules WHERE type = :t AND target_name = :n');
        $dup->execute(['t' => $type, 'n' => $targetName]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('Sudah ada jadwal backup untuk target ini - hapus atau ubah jadwal yang sudah ada');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO backup_schedules (type, target_name, interval_unit, interval_value, is_enabled, created_by)
             VALUES (:t, :n, :u, :v, 1, :uid)'
        );
        $stmt->execute(['t' => $type, 'n' => $targetName, 'u' => $intervalUnit, 'v' => $intervalValue, 'uid' => $userId]);
        $id = (int) $pdo->lastInsertId();

        ActivityLog::record($userId, 'backup.schedule_create', "Jadwal backup dibuat: {$type}:{$targetName} tiap {$intervalValue} {$intervalUnit}");

        return self::find($id);
    }

    public static function toggle(int $id, bool $enabled, ?int $userId): void
    {
        $schedule = self::find($id);
        if ($schedule === null) {
            throw new InvalidArgumentException('Jadwal tidak ditemukan');
        }
        Database::app()->prepare('UPDATE backup_schedules SET is_enabled = :e WHERE id = :id')
            ->execute(['e' => $enabled ? 1 : 0, 'id' => $id]);
        ActivityLog::record($userId, 'backup.schedule_toggle', "Jadwal backup {$schedule['type']}:{$schedule['target_name']} " . ($enabled ? 'diaktifkan' : 'dinonaktifkan'));
    }

    public static function delete(int $id, ?int $userId): void
    {
        $schedule = self::find($id);
        if ($schedule === null) {
            return;
        }
        Database::app()->prepare('DELETE FROM backup_schedules WHERE id = :id')->execute(['id' => $id]);
        ActivityLog::record($userId, 'backup.schedule_delete', "Jadwal backup dihapus: {$schedule['type']}:{$schedule['target_name']}");
    }

    private static function assertTargetExists(string $type, string $targetName): void
    {
        $pdo = Database::app();
        $exists = match ($type) {
            'database' => (function () use ($pdo, $targetName): bool {
                $s = $pdo->prepare('SELECT COUNT(*) FROM databases_registry WHERE db_name = :n');
                $s->execute(['n' => $targetName]);
                return (int) $s->fetchColumn() > 0;
            })(),
            'website' => (function () use ($pdo, $targetName): bool {
                $s = $pdo->prepare('SELECT COUNT(*) FROM websites WHERE domain = :n');
                $s->execute(['n' => $targetName]);
                return (int) $s->fetchColumn() > 0;
            })(),
            'nodejs' => (function () use ($pdo, $targetName): bool {
                $s = $pdo->prepare('SELECT COUNT(*) FROM nodejs_apps WHERE app_name = :n');
                $s->execute(['n' => $targetName]);
                return (int) $s->fetchColumn() > 0;
            })(),
            default => false,
        };
        if (!$exists) {
            throw new InvalidArgumentException('Target backup tidak ditemukan');
        }
    }

    /** @return array<int,array<string,mixed>> enabled schedules whose interval has elapsed since last_run_at (or that have never run) */
    public static function dueSchedules(): array
    {
        $due = [];
        foreach (self::listAll() as $schedule) {
            if ((bool) $schedule['is_enabled'] && self::isDue($schedule)) {
                $due[] = $schedule;
            }
        }
        return $due;
    }

    /** @param array<string,mixed> $schedule */
    public static function isDue(array $schedule): bool
    {
        if ($schedule['last_run_at'] === null) {
            return true;
        }
        $intervalSeconds = self::intervalToSeconds((string) $schedule['interval_unit'], (int) $schedule['interval_value']);
        $lastRun = strtotime((string) $schedule['last_run_at']);
        return $lastRun !== false && ($lastRun + $intervalSeconds) <= time();
    }

    private static function intervalToSeconds(string $unit, int $value): int
    {
        $unitSeconds = match ($unit) {
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            'month' => 2629800, // 30.44 days average - calendar-exact months would need date arithmetic this granularity doesn't warrant
            'year' => 31557600, // 365.25 days average (accounts for leap years on average)
            default => 86400,
        };
        return max(1, $value) * $unitSeconds;
    }

    public static function recordRun(int $id, string $status): void
    {
        Database::app()->prepare('UPDATE backup_schedules SET last_run_at = NOW(), last_run_status = :s WHERE id = :id')
            ->execute(['s' => $status, 'id' => $id]);
    }
}
