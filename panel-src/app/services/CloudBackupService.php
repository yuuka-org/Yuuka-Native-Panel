<?php
declare(strict_types=1);

/**
 * Remote backup storage - two independent, simultaneously-usable targets:
 *   - S3-compatible (AWS S3 or Backblaze B2 - B2 exposes the same S3 API
 *     through its own --endpoint-url, so one code path covers both), via
 *     the AWS CLI (op_backup_upload_s3).
 *   - Google Drive, via rclone (op_backup_upload_gdrive) - Google's OAuth
 *     model has no equivalent of a static API key an admin can just paste
 *     in, so this one needs a one-time manual step: the admin runs
 *     `rclone authorize "drive"` on THEIR OWN machine (needs a real
 *     browser to complete Google's consent screen), which prints a JSON
 *     token - that token is what gets pasted into Settings > Backup, not
 *     a password. See saveGdriveConfig()'s validation for the expected
 *     shape.
 * Both configs live in the `settings` table (SettingsService); every
 * secret (S3 secret key, Drive token, Drive OAuth client secret) is
 * encrypted at rest with the same AES-256-GCM scheme EnvService already
 * uses for Node.js env vars (same APP_KEY, same cipher - reused directly
 * instead of duplicated).
 */
final class CloudBackupService
{
    /** @return array{enabled:bool,endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_prefix:string} */
    public static function s3Config(): array
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

    public static function isS3Configured(): bool
    {
        $c = self::s3Config();
        return $c['enabled'] && $c['bucket'] !== '' && $c['access_key'] !== '' && $c['secret_key'] !== '';
    }

    /**
     * $secretKey is null/empty when the operator didn't type a new one -
     * the UI never round-trips the decrypted secret back into the form,
     * so leaving the field blank means "keep the current one", not
     * "clear it".
     */
    public static function saveS3Config(
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

        ActivityLog::record($userId, 'backup.cloud_config', 'Konfigurasi Cloud Storage S3 backup diperbarui');
    }

    /** @return array{enabled:bool,token:string,client_id:string,client_secret:string,folder_id:string,path_prefix:string} */
    public static function gdriveConfig(): array
    {
        $encToken = SettingsService::get('backup_cloud_gdrive_token_enc');
        $encClientSecret = SettingsService::get('backup_cloud_gdrive_client_secret_enc');
        return [
            'enabled' => SettingsService::get('backup_cloud_gdrive_enabled') === '1',
            'token' => $encToken === '' ? '' : EnvService::decrypt($encToken),
            'client_id' => SettingsService::get('backup_cloud_gdrive_client_id'),
            'client_secret' => $encClientSecret === '' ? '' : EnvService::decrypt($encClientSecret),
            'folder_id' => SettingsService::get('backup_cloud_gdrive_folder_id'),
            'path_prefix' => SettingsService::get('backup_cloud_gdrive_path_prefix', 'backups/'),
        ];
    }

    public static function isGdriveConfigured(): bool
    {
        $c = self::gdriveConfig();
        return $c['enabled'] && $c['token'] !== '';
    }

    /**
     * $token is the JSON blob `rclone authorize "drive"` prints after the
     * admin completes Google's OAuth consent screen on their own machine
     * (looks like {"access_token":"...","token_type":"Bearer",
     * "refresh_token":"...","expiry":"..."}) - null/empty means "keep the
     * current one", same convention as S3's secret key. $clientId/
     * $clientSecret are optional (Google Cloud OAuth app credentials, for
     * admins who want their own quota instead of rclone's shared default);
     * $folderId is an optional Google Drive folder ID to scope uploads
     * into instead of Drive's root.
     */
    public static function saveGdriveConfig(
        bool $enabled,
        ?string $token,
        string $clientId,
        ?string $clientSecret,
        string $folderId,
        string $pathPrefix,
        ?int $userId
    ): void {
        if ($enabled) {
            $existingToken = SettingsService::get('backup_cloud_gdrive_token_enc');
            if (($token === null || $token === '') && $existingToken === '') {
                throw new InvalidArgumentException('Token Google Drive wajib diisi (hasil "rclone authorize drive")');
            }
            if ($token !== null && $token !== '') {
                $decoded = json_decode($token, true);
                if (!is_array($decoded) || !isset($decoded['access_token'], $decoded['refresh_token'])) {
                    throw new InvalidArgumentException('Token tidak valid - harus JSON hasil "rclone authorize drive" (mengandung access_token & refresh_token)');
                }
            }
        }

        $pathPrefix = trim(trim($pathPrefix), '/');
        if ($pathPrefix !== '') {
            $pathPrefix .= '/';
        }

        SettingsService::set('backup_cloud_gdrive_enabled', $enabled ? '1' : '0');
        if ($token !== null && $token !== '') {
            SettingsService::set('backup_cloud_gdrive_token_enc', EnvService::encrypt($token));
        }
        SettingsService::set('backup_cloud_gdrive_client_id', trim($clientId));
        if ($clientSecret !== null && $clientSecret !== '') {
            SettingsService::set('backup_cloud_gdrive_client_secret_enc', EnvService::encrypt($clientSecret));
        }
        SettingsService::set('backup_cloud_gdrive_folder_id', trim($folderId));
        SettingsService::set('backup_cloud_gdrive_path_prefix', $pathPrefix);

        ActivityLog::record($userId, 'backup.cloud_config', 'Konfigurasi Google Drive backup diperbarui');
    }

    /**
     * Uploads one already-completed local backup to every configured
     * remote target (S3 and/or Google Drive - independent, both run if
     * both are enabled). Called automatically right after every
     * successful local backup (BackupService::finalize()) - both manual
     * "Backup" button clicks and scheduled runs (backup_scheduler_runner.php)
     * funnel through the same finalize(), so both get this for free.
     * Failure here never fails the backup itself - the LOCAL copy is
     * already safely on disk regardless, this is a best-effort mirror.
     */
    public static function uploadIfConfigured(int $backupId): void
    {
        if (!self::isS3Configured() && !self::isGdriveConfigured()) {
            return;
        }
        $backup = BackupService::find($backupId);
        if ($backup === null || $backup['status'] !== 'completed' || !is_file($backup['file_path'])) {
            return;
        }
        $filename = basename($backup['file_path']);

        if (self::isS3Configured()) {
            self::uploadToS3($backupId, $filename);
        }
        if (self::isGdriveConfigured()) {
            self::uploadToGdrive($backupId, $filename);
        }
    }

    private static function uploadToS3(int $backupId, string $filename): void
    {
        $config = self::s3Config();
        $payload = json_encode([
            'endpoint' => $config['endpoint'],
            'region' => $config['region'],
            'bucket' => $config['bucket'],
            'prefix' => $config['path_prefix'],
            'access_key' => $config['access_key'],
            'secret_key' => $config['secret_key'],
        ]);

        $result = Executor::run('backup-upload-s3', [$filename], $payload, 180);
        if ($result['ok']) {
            $remotePath = $config['path_prefix'] . $filename;
            Database::app()->prepare('UPDATE backups SET cloud_uploaded = 1, cloud_uploaded_at = NOW(), cloud_path = :p WHERE id = :id')
                ->execute(['p' => $remotePath, 'id' => $backupId]);
        } else {
            @file_put_contents(LOG_PATH . '/backup-cloud-upload.log', '[' . date('c') . "] GAGAL upload S3 {$filename}: {$result['output']}\n", FILE_APPEND);
        }
    }

    private static function uploadToGdrive(int $backupId, string $filename): void
    {
        $config = self::gdriveConfig();
        $payload = json_encode([
            'token' => $config['token'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'folder_id' => $config['folder_id'],
            'prefix' => $config['path_prefix'],
        ]);

        $result = Executor::run('backup-upload-gdrive', [$filename], $payload, 180);
        if ($result['ok']) {
            $remotePath = $config['path_prefix'] . $filename;
            Database::app()->prepare('UPDATE backups SET cloud_uploaded_gdrive = 1, cloud_uploaded_gdrive_at = NOW(), cloud_path_gdrive = :p WHERE id = :id')
                ->execute(['p' => $remotePath, 'id' => $backupId]);
        } else {
            @file_put_contents(LOG_PATH . '/backup-cloud-upload.log', '[' . date('c') . "] GAGAL upload Google Drive {$filename}: {$result['output']}\n", FILE_APPEND);
        }
    }
}
