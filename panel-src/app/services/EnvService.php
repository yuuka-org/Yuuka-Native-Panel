<?php
declare(strict_types=1);

/**
 * Encrypts/decrypts Node.js application environment variable values at
 * rest using AES-256-GCM, keyed from APP_KEY (.env, 600 permissions).
 * Secret values are never written to logs and are masked by default in
 * the UI (show/hide toggle reveals the decrypted value client-side only
 * after an explicit user action).
 */
final class EnvService
{
    private static function key(): string
    {
        $hex = Config::get('APP_KEY', '');
        if (strlen($hex) !== 64) {
            throw new RuntimeException('APP_KEY tidak valid, jalankan ulang installer atau set APP_KEY 64 hex char di .env');
        }
        return hex2bin($hex);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Enkripsi gagal');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /** @return array<string,array{value:string,is_secret:bool}> keyed by var name, decrypted */
    public static function listForApp(int $appId): array
    {
        $stmt = Database::app()->prepare('SELECT var_key, var_value_enc, is_secret FROM app_env_variables WHERE app_id = :id ORDER BY var_key');
        $stmt->execute(['id' => $appId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['var_key']] = [
                'value' => self::decrypt($row['var_value_enc']),
                'is_secret' => (bool) $row['is_secret'],
            ];
        }
        return $result;
    }

    /** @return array<string,string> plain key=>value map, for feeding into the PM2 ecosystem file */
    public static function plainMapForApp(int $appId): array
    {
        $map = [];
        foreach (self::listForApp($appId) as $key => $data) {
            $map[$key] = $data['value'];
        }
        return $map;
    }

    public static function setVariable(int $appId, string $key, string $value, bool $isSecret): void
    {
        if (!Validator::envVarName($key)) {
            throw new InvalidArgumentException('Nama environment variable tidak valid');
        }
        if (!Validator::envVarValue($value)) {
            throw new InvalidArgumentException('Nilai environment variable tidak valid');
        }

        $encrypted = self::encrypt($value);
        $stmt = Database::app()->prepare(
            'INSERT INTO app_env_variables (app_id, var_key, var_value_enc, is_secret)
             VALUES (:app_id, :key, :val, :secret)
             ON DUPLICATE KEY UPDATE var_value_enc = :val2, is_secret = :secret2'
        );
        $stmt->execute([
            'app_id' => $appId, 'key' => $key, 'val' => $encrypted, 'secret' => $isSecret ? 1 : 0,
            'val2' => $encrypted, 'secret2' => $isSecret ? 1 : 0,
        ]);
    }

    public static function deleteVariable(int $appId, string $key): void
    {
        $stmt = Database::app()->prepare('DELETE FROM app_env_variables WHERE app_id = :app_id AND var_key = :key');
        $stmt->execute(['app_id' => $appId, 'key' => $key]);
    }

    /** Parses a .env-formatted string into KEY=VALUE pairs, validating each. */
    public static function parseDotEnv(string $content): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
                $v = substr($v, 1, -1);
            }
            if (Validator::envVarName($k) && Validator::envVarValue($v)) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    /** Nama file yang dianggap file environment: .env, .env.production, production.env, dst. */
    public static function isEnvFileName(string $fileName): bool
    {
        return (bool) preg_match('/^\.env(\.[A-Za-z0-9_-]+)?$/', $fileName)
            || (bool) preg_match('/^[A-Za-z0-9_-]+\.env$/', $fileName);
    }

    /**
     * File environment di root folder app (.env, .env.*, *.env) - sumber untuk "Import dari file".
     * @return string[]
     */
    public static function envFilesInAppDir(string $appName): array
    {
        $names = [];
        try {
            foreach (FileManagerService::listDir('nodeapp', $appName, '') as $entry) {
                if ($entry['type'] === 'file' && self::isEnvFileName($entry['name'])) {
                    $names[] = $entry['name'];
                }
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            // folder belum ada / tidak terbaca: daftar kosong
        }
        return $names;
    }

    /**
     * Import isi .env ke variabel app (ditimpa jika key sama). Key yang
     * mengandung secret/password/key otomatis ditandai secret.
     * @return int jumlah variabel yang diimpor
     */
    public static function importDotEnv(int $appId, string $content): int
    {
        $parsed = self::parseDotEnv($content);
        foreach ($parsed as $k => $v) {
            $lk = strtolower($k);
            self::setVariable($appId, $k, $v, str_contains($lk, 'secret') || str_contains($lk, 'password') || str_contains($lk, 'key'));
        }
        return count($parsed);
    }

    public static function toDotEnvExport(int $appId): string
    {
        $lines = [];
        foreach (self::listForApp($appId) as $key => $data) {
            $escaped = str_replace('"', '\"', $data['value']);
            $lines[] = "{$key}=\"{$escaped}\"";
        }
        return implode("\n", $lines) . "\n";
    }
}
