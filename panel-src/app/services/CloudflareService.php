<?php
declare(strict_types=1);

/**
 * Reads Cloudflare Tunnel status live from systemd/cloudflared. Never
 * reads or displays the tunnel token - the token lives only at
 * /etc/cloudflared/tunnel.env (600, root-owned, loaded by systemd via
 * EnvironmentFile=) and is never touched by the panel application layer.
 */
final class CloudflareService
{
    /**
     * The panel PHP-FPM pool's open_basedir is locked to its own directory
     * (see modules/panel.sh), so presence is inferred from the Executor
     * (which runs as root, unrestricted) rather than checking the
     * filesystem path directly from PHP.
     */
    public static function isInstalled(): bool
    {
        return self::binaryVersion() !== null;
    }

    public static function status(): array
    {
        $version = self::binaryVersion();
        if ($version === null) {
            return [
                'configured' => false,
                'status' => 'not_configured',
                'version' => null,
            ];
        }

        $result = Executor::run('cloudflared-status', [], null, 10);
        $status = trim($result['output']);
        if ($status === '') {
            $status = 'unknown';
        }

        return [
            'configured' => true,
            'status' => $status, // active | inactive | failed | unknown
            'version' => $version,
        ];
    }

    private static function binaryVersion(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $result = Executor::run('cloudflared-version', [], null, 10);
        $cached = $result['ok'] && trim($result['output']) !== '' ? trim($result['output']) : null;
        return $cached;
    }

    public static function restart(?int $userId): void
    {
        $result = Executor::run('cloudflared-restart', [], null, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal restart cloudflared: ' . $result['output']);
        }
        ActivityLog::record($userId, 'cloudflare.restart', 'cloudflared di-restart');
    }

    public static function stop(?int $userId): void
    {
        $result = Executor::run('cloudflared-stop', [], null, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal stop cloudflared: ' . $result['output']);
        }
        ActivityLog::record($userId, 'cloudflare.stop', 'cloudflared dihentikan');
    }

    public static function start(?int $userId): void
    {
        $result = Executor::run('cloudflared-start', [], null, 20);
        if (!$result['ok']) {
            throw new RuntimeException('Gagal start cloudflared: ' . $result['output']);
        }
        ActivityLog::record($userId, 'cloudflare.start', 'cloudflared dijalankan');
    }

    /**
     * Cloudflare's published edge IP ranges (cloudflare.com/ips) - used to
     * auto-detect whether a domain is proxied through Cloudflare, by
     * resolving its A/AAAA records and checking them against this list.
     * Hardcoded rather than fetched live: PHP-FPM's pool has
     * allow_url_fopen=off and no shell access (see modules/panel.sh), and
     * these ranges change rarely enough that a hardcoded snapshot is a
     * reasonable, dependency-free tradeoff over adding an HTTP client
     * just for this. Update this list if Cloudflare publishes a change.
     */
    private const CF_IPV4_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];
    private const CF_IPV6_RANGES = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Resolves $domain's current A/AAAA records and checks whether ANY of
     * them fall inside Cloudflare's published edge ranges - this is what
     * "proxied" (orange cloud) actually looks like from the outside: the
     * public DNS answer points at Cloudflare's edge instead of the
     * origin's real IP. Returns null if the domain doesn't resolve at all
     * (down/not yet propagated) rather than guessing.
     */
    public static function isDomainProxied(string $domain): ?bool
    {
        $records = @dns_get_record($domain, DNS_A + DNS_AAAA);
        if ($records === false || empty($records)) {
            return null;
        }

        foreach ($records as $record) {
            $ip = $record['type'] === 'AAAA' ? ($record['ipv6'] ?? '') : ($record['ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            $ranges = $record['type'] === 'AAAA' ? self::CF_IPV6_RANGES : self::CF_IPV4_RANGES;
            foreach ($ranges as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsStr] = explode('/', $cidr);
        $bits = (int) $bitsStr;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainderBits = $bits % 8;
        if ($remainderBits > 0) {
            $mask = (~(0xFF >> $remainderBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
