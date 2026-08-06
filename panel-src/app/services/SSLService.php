<?php
declare(strict_types=1);

final class SSLService
{
    /** @throws InvalidArgumentException if the domain isn't registered in the panel at all */
    private static function domainType(string $domain): string
    {
        $stmt = Database::app()->prepare('SELECT type FROM domains WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        $type = $stmt->fetchColumn();
        if ($type === false) {
            throw new InvalidArgumentException('Domain tidak ditemukan di panel');
        }
        return (string) $type;
    }

    public static function issueForDomain(string $domain, string $email, ?int $userId): void
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!Validator::email($email)) {
            throw new InvalidArgumentException('Email tidak valid');
        }
        $type = self::domainType($domain);

        $result = Executor::run('certbot-issue', [$domain, $email], null, 90);
        if (!$result['ok']) {
            throw new RuntimeException('Penerbitan SSL gagal (pastikan DNS domain sudah mengarah ke server ini): ' . $result['output']);
        }

        // Cert files exist on disk now - wire them into Nginx's 443 block
        // BEFORE telling the DB/UI SSL is on. Without this step (the
        // actual bug this fixes), ssl_enabled flips to 1 purely because
        // certbot exited 0, while Nginx never opens 443 for the domain at
        // all - the panel shows "SSL Issued" but the origin never
        // completes a TLS handshake (Cloudflare: "SSL handshake failed").
        try {
            match ($type) {
                'php' => NginxService::applySslForDomain($domain, true),
                'nodejs' => NodeService::applySslForDomain($domain, true),
                default => throw new RuntimeException("Tipe domain tidak dikenal: {$type}"),
            };
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw new RuntimeException("Sertifikat terbit untuk {$domain} tapi gagal diterapkan ke Nginx: " . $e->getMessage());
        }

        Database::app()->prepare('UPDATE domains SET ssl_enabled = 1 WHERE domain = :d')->execute(['d' => $domain]);
        Database::app()->prepare('UPDATE websites SET ssl_enabled = 1 WHERE domain = :d')->execute(['d' => $domain]);

        ActivityLog::record($userId, 'ssl.issue', "SSL diterbitkan untuk {$domain}");
    }

    /**
     * SSL for the PANEL'S OWN domain - deliberately separate from
     * issueForDomain() above, which only ever operates on rows in the
     * `domains`/`websites` tables (customer sites). The panel's own vhost
     * isn't tracked there at all, so panel-exec.sh's op_panel_ssl_issue
     * derives the domain itself from the live Nginx vhost on disk rather
     * than trusting a caller-supplied value - this method intentionally
     * takes no $domain parameter to match that contract.
     */
    public static function issueForPanelDomain(string $email, ?int $userId): void
    {
        if (!Validator::email($email)) {
            throw new InvalidArgumentException('Email tidak valid');
        }

        $result = Executor::run('panel-ssl-issue', [$email], null, 90);
        if (!$result['ok']) {
            throw new RuntimeException('Penerbitan SSL untuk domain panel gagal (pastikan DNS domain panel sudah mengarah ke server ini): ' . $result['output']);
        }

        ActivityLog::record($userId, 'ssl.issue_panel', 'SSL diterbitkan untuk domain panel');
    }

    public static function removeCertificate(string $domain, ?int $userId): void
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        $type = self::domainType($domain);

        // Revert Nginx to plain HTTP FIRST, before touching the cert
        // files on disk - if this fails, the working 443 block and its
        // cert are left untouched instead of half-torn-down. Once this
        // succeeds Nginx is genuinely no longer serving TLS for this
        // domain, so the DB is updated right away regardless of whether
        // the certbot cleanup below succeeds - "SSL: Tidak aktif" must
        // always match what's actually being served.
        match ($type) {
            'php' => NginxService::applySslForDomain($domain, false),
            'nodejs' => NodeService::applySslForDomain($domain, false),
            default => throw new RuntimeException("Tipe domain tidak dikenal: {$type}"),
        };

        Database::app()->prepare('UPDATE domains SET ssl_enabled = 0 WHERE domain = :d')->execute(['d' => $domain]);
        Database::app()->prepare('UPDATE websites SET ssl_enabled = 0 WHERE domain = :d')->execute(['d' => $domain]);

        $result = Executor::run('certbot-remove', [$domain], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Nginx sudah dikembalikan ke HTTP biasa, tapi gagal menghapus berkas sertifikat: ' . $result['output']);
        }

        ActivityLog::record($userId, 'ssl.remove', "SSL dihapus untuk {$domain}");
    }

    /**
     * Manual cert/key upload - the alternative to Let's Encrypt for
     * domains with a commercially-issued cert, or that certbot's HTTP-01
     * challenge can't reach (traffic only ever arriving through
     * Cloudflare, no direct port 80 access). Every check here runs in
     * PHP with openssl_* BEFORE anything is sent to panel-exec.sh -
     * op_ssl_manual_upload() re-validates independently (its own
     * privilege boundary can't just trust this layer's say-so), but
     * failing fast here means a bad upload never even reaches root.
     */
    public static function uploadManualCertificate(string $domain, string $certPem, string $keyPem, ?int $userId): void
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        $type = self::domainType($domain);

        $certInfo = openssl_x509_parse($certPem);
        if ($certInfo === false) {
            throw new InvalidArgumentException('Berkas sertifikat tidak valid (harus format PEM)');
        }
        if (openssl_pkey_get_private($keyPem) === false) {
            throw new InvalidArgumentException('Berkas private key tidak valid, tidak didukung, atau butuh passphrase (passphrase tidak didukung)');
        }
        if (!openssl_x509_check_private_key($certPem, $keyPem)) {
            throw new InvalidArgumentException('Private key tidak cocok dengan sertifikat');
        }
        $validTo = (int) ($certInfo['validTo_time_t'] ?? 0);
        if ($validTo > 0 && $validTo < time()) {
            throw new InvalidArgumentException('Sertifikat sudah kedaluwarsa pada ' . date('Y-m-d', $validTo));
        }
        if (!self::certCoversDomain($certInfo, $domain)) {
            throw new InvalidArgumentException("Sertifikat ini tidak berlaku untuk domain {$domain} (Common Name/Subject Alternative Name tidak cocok)");
        }

        $payload = json_encode(['cert' => $certPem, 'key' => $keyPem]);
        $result = Executor::run('ssl-manual-upload', [$domain], $payload, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menyimpan sertifikat: ' . $result['output']);
        }

        try {
            match ($type) {
                'php' => NginxService::applySslForDomain($domain, true),
                'nodejs' => NodeService::applySslForDomain($domain, true),
                default => throw new RuntimeException("Tipe domain tidak dikenal: {$type}"),
            };
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw new RuntimeException("Sertifikat tersimpan tapi gagal diterapkan ke Nginx: " . $e->getMessage());
        }

        Database::app()->prepare('UPDATE domains SET ssl_enabled = 1 WHERE domain = :d')->execute(['d' => $domain]);
        Database::app()->prepare('UPDATE websites SET ssl_enabled = 1 WHERE domain = :d')->execute(['d' => $domain]);

        ActivityLog::record($userId, 'ssl.manual_upload', "Sertifikat SSL manual diunggah untuk {$domain}");
    }

    /** @param array<string,mixed> $certInfo openssl_x509_parse() output */
    private static function certCoversDomain(array $certInfo, string $domain): bool
    {
        $domain = strtolower($domain);
        $names = [];
        $cn = (string) ($certInfo['subject']['CN'] ?? '');
        if ($cn !== '') {
            $names[] = $cn;
        }
        $san = (string) ($certInfo['extensions']['subjectAltName'] ?? '');
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }

        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if ($name === $domain) {
                return true;
            }
            if (str_starts_with($name, '*.') && str_ends_with($domain, substr($name, 1))) {
                return true;
            }
        }
        return false;
    }
}
