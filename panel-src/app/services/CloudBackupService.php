<?php
declare(strict_types=1);

/**
 * S3-compatible remote backup storage (AWS S3 or Backblaze B2 - B2
 * exposes the same S3 API through its own --endpoint-url, so one code
 * path covers both). Config lives in the `settings` table
 * (SettingsService); the secret key is encrypted at rest with the same
 * AES-256-GCM scheme EnvService already uses for Node.js env vars (same
 * APP_KEY, same cipher - reused directly instead of duplicated).
 */
final class CloudBackupService
{
    /** @return array{enabled:bool,endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_prefix:string} */
    public static function config(): array
    {
        $encSecret = SettingsService::get('backup_cloud_secret_key_enc');
        return [
            'enabled' => SettingsService::get('backup_cloud_enabled') === '1',
            'endpoint' => SettingsService::get('backup_cloud_endpoint'),
            'region' => SettingsService::get('backup_cloud_region'),
            'bucket' => SettingsService::get('backup_cloud_bucket'),
            'access_key' => SettingsService::get('backup_cloud_access_key'),
            'secret_key' => $encSecret === '' ? '' : EnvService::decrypt($encSecret),
            'path_prefix' => SettingsService::get('backup_cloud_path_prefix', 'backups/'),
        ];
    }

    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['bucket'] !== '' && $c['access_key'] !== '' && $c['secret_key'] !== '';
    }

    /**
     * $secretKey is null/empty when the operator didn't type a new one -
     * the UI never round-trips the decrypted secret back into the form,
     * so leaving the field blank means "keep the current one", not
     * "clear it".
     */
    public static function saveConfig(
        bool $enabled,
        string $endpoint,
        string $region,
        string $bucket,
        string $accessKey,
        ?string $secretKey,
        string $pathPrefix,
        ?int $userId
    ): void {
        if ($enabled) {
            if ($bucket === '') {
                throw new InvalidArgumentException('Nama Bucket wajib diisi');
            }
            if ($accessKey === '') {
                throw new InvalidArgumentException('Access Key wajib diisi');
            }
            if ($endpoint !== '' && filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Endpoint harus URL valid (contoh: https://s3.us-west-002.backblazeb2.com), kosongkan untuk AWS S3 default');
            }
            $existingSecret = SettingsService::get('backup_cloud_secret_key_enc');
            if (($secretKey === null || $secretKey === '') && $existingSecret === '') {
                throw new InvalidArgumentException('Secret Key wajib diisi');
            }
        }

        $pathPrefix = trim(trim($pathPrefix), '/');
        if ($pathPrefix !== '') {
            $pathPrefix .= '/';
        }

        SettingsService::set('backup_cloud_enabled', $enabled ? '1' : '0');
        SettingsService::set('backup_cloud_endpoint', trim($endpoint));
        SettingsService::set('backup_cloud_region', trim($region));
        SettingsService::set('backup_cloud_bucket', trim($bucket));
        SettingsService::set('backup_cloud_access_key', trim($accessKey));
        if ($secretKey !== null && $secretKey !== '') {
            SettingsService::set('backup_cloud_secret_key_enc', EnvService::encrypt($secretKey));
        }
        SettingsService::set('backup_cloud_path_prefix', $pathPrefix);

        ActivityLog::record($userId, 'backup.cloud_config', 'Konfigurasi Cloud Storage backup diperbarui');
    }

    /**
     * Uploads one already-completed local backup to the configured
     * S3-compatible bucket. Called automatically right after every
     * successful local backup (BackupService::finalize()) - both manual
     * "Backup" button clicks and scheduled runs (backup_scheduler_runner.php)
     * funnel through the same finalize(), so both get this for free.
     * Failure here never fails the backup itself - the LOCAL copy is
     * already safely on disk regardless, this is a best-effort mirror.
     */
    public static function uploadIfConfigured(int $backupId): void
    {
        if (!self::isConfigured()) {
            return;
        }
        $backup = BackupService::find($backupId);
        if ($backup === null || $backup['status'] !== 'completed' || !is_file($backup['file_path'])) {
            return;
        }

        $config = self::config();
        $filename = basename($backup['file_path']);
        $payload = json_encode([
            'endpoint' => $config['endpoint'],
            'region' => $config['region'],
            'bucket' => $config['bucket'],
            'prefix' => $config['path_prefix'],
            'access_key' => $config['access_key'],
            'secret_key' => $config['secret_key'],
        ]);

        $result = Executor::run('backup-upload-s3', [$filename], $payload, 180);
        $pdo = Database::app();
        if ($result['ok']) {
            $remotePath = $config['path_prefix'] . $filename;
            $pdo->prepare('UPDATE backups SET cloud_uploaded = 1, cloud_uploaded_at = NOW(), cloud_path = :p WHERE id = :id')
                ->execute(['p' => $remotePath, 'id' => $backupId]);
        } else {
            @file_put_contents(LOG_PATH . '/backup-cloud-upload.log', '[' . date('c') . "] GAGAL upload {$filename}: {$result['output']}\n", FILE_APPEND);
        }
    }
}
