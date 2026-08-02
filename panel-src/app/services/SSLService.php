<?php
declare(strict_types=1);

final class SSLService
{
    public static function issueForDomain(string $domain, string $email, ?int $userId): void
    {
        if (!Validator::domain($domain)) {
            throw new InvalidArgumentException('Domain tidak valid');
        }
        if (!Validator::email($email)) {
            throw new InvalidArgumentException('Email tidak valid');
        }

        $result = Executor::run('certbot-issue', [$domain, $email], null, 90);
        if (!$result['ok']) {
            throw new RuntimeException('Penerbitan SSL gagal (pastikan DNS domain sudah mengarah ke server ini): ' . $result['output']);
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

        $result = Executor::run('panel-ssl-issue', [$email], null, 120);
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

        $result = Executor::run('certbot-remove', [$domain], null, 30);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal menghapus sertifikat: ' . $result['output']);
        }

        Database::app()->prepare('UPDATE domains SET ssl_enabled = 0 WHERE domain = :d')->execute(['d' => $domain]);
        Database::app()->prepare('UPDATE websites SET ssl_enabled = 0 WHERE domain = :d')->execute(['d' => $domain]);

        ActivityLog::record($userId, 'ssl.remove', "SSL dihapus untuk {$domain}");
    }
}
