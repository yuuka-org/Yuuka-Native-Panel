<?php
declare(strict_types=1);

/**
 * Unified read/administration layer over the `domains` table. Domain
 * *creation* always happens as part of NginxService::createWebsite() or
 * NodeService::createApp() (a domain without a backing website/app makes
 * no sense) - this service handles listing, enable/disable and the
 * Cloudflare-proxied metadata flag.
 */
final class DomainService
{
    /** @return array<int,array<string,mixed>> */
    public static function listAll(): array
    {
        return Database::app()->query(
            'SELECT d.*, w.php_version, w.is_enabled AS website_enabled, w.document_root,
                    n.app_name, n.port
             FROM domains d
             LEFT JOIN websites w ON w.id = d.website_id
             LEFT JOIN nodejs_apps n ON n.id = d.nodejs_app_id
             ORDER BY d.domain'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Auto-detects whether $domain is currently proxied through Cloudflare
     * (its public DNS resolves to one of Cloudflare's published edge
     * ranges - see CloudflareService::isDomainProxied()) and persists the
     * result. Replaces the old fully-manual admin toggle: this is
     * directly observable from DNS, so there's no reason to make the
     * admin assert it by hand and risk it drifting from reality (which is
     * exactly what caused the "SSL Issued but Cloudflare still shows
     * handshake failed"-style confusion this was built to help diagnose).
     * Returns null (leaving the stored value untouched) if the domain
     * doesn't currently resolve at all, rather than overwriting a
     * previously-known value with a guess.
     */
    public static function syncCloudflareProxied(int $id, string $domain): ?bool
    {
        $detected = CloudflareService::isDomainProxied($domain);
        if ($detected === null) {
            return null;
        }
        Database::app()->prepare('UPDATE domains SET cloudflare_proxied = :p WHERE id = :id')
            ->execute(['p' => $detected ? 1 : 0, 'id' => $id]);
        return $detected;
    }

    public static function toggle(int $id, bool $enable, ?int $userId): void
    {
        $domain = self::find($id);
        if ($domain === null) {
            throw new InvalidArgumentException('Domain tidak ditemukan');
        }

        if ($domain['type'] === 'php' && $domain['website_id']) {
            NginxService::toggleWebsite((int) $domain['website_id'], $enable, $userId);
        } elseif ($domain['type'] === 'nodejs' && $domain['nodejs_app_id']) {
            $siteName = 'node-' . $domain['domain'];
            $result = $enable ? nginx_enable_site($siteName) : nginx_disable_site($siteName);
            if (!$result['ok']) {
                throw new RuntimeException('Gagal mengubah status domain: ' . $result['output']);
            }
        }

        Database::app()->prepare('UPDATE domains SET is_enabled = :e WHERE id = :id')
            ->execute(['e' => $enable ? 1 : 0, 'id' => $id]);
    }
}
