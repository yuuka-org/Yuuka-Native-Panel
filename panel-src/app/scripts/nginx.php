<?php
declare(strict_types=1);

/**
 * Low-level Nginx config builders + Executor wrappers. Callers (NginxService)
 * are responsible for validating domain/php-version/paths beforehand.
 */

/**
 * Must match exactly between a site's own `limit_req zone=X` reference and
 * the shared conf.d zone declaration (nginx_build_rate_limit_zones_config)
 * - both derive the name from the domain via this one function so they
 * can never drift apart. Hashed rather than a lossy character-replacement
 * scheme (e.g. stripping non-alnum) - two DIFFERENT domains like
 * "a.b.com" and "a-b.com" would otherwise both sanitize to the exact same
 * "zone_a_b_com", silently sharing one rate limit bucket between two
 * unrelated sites (or failing nginx -t outright on the duplicate zone
 * declaration).
 */
function nginx_rate_limit_zone_name(string $domain): string
{
    return 'zone_' . substr(md5(strtolower($domain)), 0, 12);
}

/**
 * `limit_req_zone` is only valid at http{} context, never inside a single
 * server{} block - every rate-limited site's zone is declared together in
 * ONE shared file (conf.d, auto-included at http level by Debian/Ubuntu's
 * stock nginx.conf), fully regenerated from the current DB state whenever
 * any site's Traffic Control setting changes.
 * @param array<int,array{domain:string,rate_limit_rps:int}> $rateLimitedSites
 */
function nginx_build_rate_limit_zones_config(array $rateLimitedSites): string
{
    if (empty($rateLimitedSites)) {
        return "# Tidak ada situs dengan Traffic Control aktif saat ini\n";
    }
    $lines = ["# Auto-generated - jangan edit manual, akan ditimpa dari Settings tiap situs"];
    foreach ($rateLimitedSites as $s) {
        $zone = nginx_rate_limit_zone_name($s['domain']);
        $rps = max(1, (int) $s['rate_limit_rps']);
        $lines[] = "limit_req_zone \$binary_remote_addr zone={$zone}:10m rate={$rps}r/s;";
    }
    return implode("\n", $lines) . "\n";
}

/** @param array{extensions:string,referrers:string} $hotlink */
function nginx_build_hotlink_block(string $domain, array $hotlink): string
{
    $extensions = trim($hotlink['extensions']) !== '' ? $hotlink['extensions'] : 'jpg|jpeg|png|gif|webp|svg|mp4|mp3|css|js';
    $referrers = array_values(array_filter(array_map('trim', explode("\n", (string) $hotlink['referrers']))));
    $allowList = implode(' ', array_merge([$domain, "*.{$domain}"], $referrers));

    return <<<CONF

    location ~* \.({$extensions})\$ {
        valid_referers none blocked {$allowList};
        if (\$invalid_referer) {
            return 403;
        }
        try_files \$uri =404;
    }
CONF;
}

/**
 * @param array{
 *   default_index?:string, custom_rewrite_rules?:string,
 *   redirect_target?:string,
 *   rate_limit?:array{rps:int,burst:int}|null,
 *   hotlink?:array{extensions:string,referrers:string}|null,
 *   reverse_proxies?:array<int,array{path_prefix:string,target_url:string}>,
 *   ssl_enabled?:bool
 * } $options
 */
function nginx_build_php_site_config(string $domain, string $phpVersion, string $documentRoot, array $options = []): string
{
    // A full-site Redirect short-circuits everything else below - nothing
    // is actually served from disk for this domain, every request just
    // bounces onward. Still needs its own SSL branch though - a redirect
    // domain (e.g. old.com -> new.com) legitimately wants a valid cert too,
    // so the browser never sees a TLS warning before the redirect ever
    // fires.
    if (!empty($options['redirect_target'])) {
        $target = $options['redirect_target'];
        if (empty($options['ssl_enabled'])) {
            return <<<CONF
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};
    include snippets/acme-challenge.conf;
    return 301 {$target}\$request_uri;
}
CONF;
        }
        $sslDirectives = nginx_ssl_cert_directives($domain);
        $mainBlock = <<<CONF
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$domain};
{$sslDirectives}
    return 301 {$target}\$request_uri;
}
CONF;
        return $mainBlock . "\n\n" . nginx_http_redirect_block($domain);
    }

    $ssl = !empty($options['ssl_enabled']);
    $sock = "/run/php/php{$phpVersion}-fpm.sock";
    $index = trim((string) ($options['default_index'] ?? '')) !== '' ? trim($options['default_index']) : 'index.php index.html';

    $extra = '';
    if (!empty($options['rate_limit'])) {
        $zone = nginx_rate_limit_zone_name($domain);
        $burst = max(0, (int) $options['rate_limit']['burst']);
        $extra .= "    limit_req zone={$zone} burst={$burst} nodelay;\n";
    }
    if (!empty($options['hotlink'])) {
        $extra .= nginx_build_hotlink_block($domain, $options['hotlink']);
    }

    $rewriteBlock = '';
    if (!empty($options['custom_rewrite_rules'])) {
        $rewriteBlock = "\n    # Custom URL Rewrite (dari Settings > Traffic & Rewrite)\n    " . trim($options['custom_rewrite_rules']) . "\n";
    }

    $reverseProxyLocations = '';
    foreach ($options['reverse_proxies'] ?? [] as $rp) {
        $reverseProxyLocations .= <<<LOC

    location {$rp['path_prefix']} {
        proxy_pass {$rp['target_url']};
        include snippets/proxy-params.conf;
    }
LOC;
    }

    // SSL sites split into two server{} blocks: this one on 443 serving
    // the real site, plus a companion 80 block (appended below) that only
    // keeps ACME renewal alive and redirects everything else to https -
    // the acme-challenge include has no reason to live in the 443 block
    // since certbot's webroot method always talks to port 80.
    $listen = $ssl
        ? "    listen 443 ssl http2;\n    listen [::]:443 ssl http2;"
        : "    listen 80;\n    listen [::]:80;";
    $acmeInclude = $ssl ? '' : "    include snippets/acme-challenge.conf;\n";
    $sslDirectives = $ssl ? nginx_ssl_cert_directives($domain) . "\n" : '';
    $logSuffix = $ssl ? '-ssl' : '';

    $mainBlock = <<<CONF
server {
{$listen}
    server_name {$domain};
{$acmeInclude}{$sslDirectives}    include snippets/cloudflare-realip.conf;

    root {$documentRoot};
    index {$index};

    access_log /var/log/nginx/{$domain}{$logSuffix}-access.log;
    error_log  /var/log/nginx/{$domain}{$logSuffix}-error.log;
{$extra}
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
{$rewriteBlock}
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$sock};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) { deny all; }
{$reverseProxyLocations}
    include snippets/security-headers.conf;
}
CONF;

    return $ssl ? $mainBlock . "\n\n" . nginx_http_redirect_block($domain) : $mainBlock;
}

function nginx_build_nodejs_proxy_config(string $domain, int $port, bool $sslEnabled = false): string
{
    $listen = $sslEnabled
        ? "    listen 443 ssl http2;\n    listen [::]:443 ssl http2;"
        : "    listen 80;\n    listen [::]:80;";
    $acmeInclude = $sslEnabled ? '' : "    include snippets/acme-challenge.conf;\n";
    $sslDirectives = $sslEnabled ? nginx_ssl_cert_directives($domain) . "\n" : '';
    $logSuffix = $sslEnabled ? '-ssl' : '';

    $mainBlock = <<<CONF
server {
{$listen}
    server_name {$domain};
{$acmeInclude}{$sslDirectives}    include snippets/cloudflare-realip.conf;

    access_log /var/log/nginx/{$domain}{$logSuffix}-access.log;
    error_log  /var/log/nginx/{$domain}{$logSuffix}-error.log;

    location / {
        proxy_pass http://127.0.0.1:{$port};
        include snippets/proxy-params.conf;
    }

    include snippets/security-headers.conf;
}
CONF;

    return $sslEnabled ? $mainBlock . "\n\n" . nginx_http_redirect_block($domain) : $mainBlock;
}

/**
 * Cloudflare for SaaS "Custom Hostnames": Cloudflare forwards traffic for
 * arbitrary customer-owned domains to a single fallback origin, so this
 * vhost can't be registered per-domain like every other site here - it
 * has to accept ANY Host header. `default_server` makes it nginx's
 * catch-all for this listen socket (only one site may hold this per
 * PORT, enforced in NginxService::findFreeWildcardPort()/wildcardSlots()
 * before this is ever written - each site gets its own dedicated port
 * so more than one wildcard slot can coexist, see migration
 * 2026072901) - `server_name _` alone does nothing without it, "_" isn't
 * special syntax, it's just a conventional placeholder hostname nobody
 * would ever actually send as a real Host header.
 */
/**
 * Port 80 keeps its historical bind-all-interfaces behavior (it's the
 * server's normal shared public HTTP port - every other site's traffic,
 * ACME challenges, etc already share this exact same listening socket,
 * and the very first wildcard slot deliberately being reachable via
 * direct IP access, not just through Cloudflare, is already documented,
 * pre-existing, accepted behavior). Every OTHER wildcard port is a
 * brand-new dedicated port that nothing else on the server binds to -
 * its only intended access path is its own dedicated Cloudflare Tunnel
 * instance connecting locally, so it's bound to loopback only. This is a
 * real security improvement, not just style: without it, each additional
 * wildcard slot would be a brand-new bare HTTP port wide open to the
 * entire internet, bypassing Cloudflare's proxy/WAF/TLS entirely.
 */
function nginx_wildcard_listen_directives(int $listenPort): string
{
    if ($listenPort === 80) {
        return "    listen 80 default_server;\n    listen [::]:80 default_server;";
    }
    return "    listen 127.0.0.1:{$listenPort} default_server;\n    listen [::1]:{$listenPort} default_server;";
}

/**
 * $listenPort is this wildcard SLOT's own dedicated local port - NOT
 * always 80. More than one site can hold a wildcard slot at once (see
 * migration 2026072901), each bound to a DIFFERENT $listenPort, which is
 * what makes that possible at all: nginx allows exactly one
 * default_server per listen address:PORT, but any number of DIFFERENT
 * ports may each have their own. Reaching a given slot from Cloudflare
 * still needs its own independent Tunnel instance whose Catch-All Rule
 * points at this exact port - see wiki/Fitur-Panel.md.
 */
function nginx_build_php_wildcard_site_config(string $phpVersion, string $documentRoot, int $listenPort): string
{
    $sock = "/run/php/php{$phpVersion}-fpm.sock";
    $listen = nginx_wildcard_listen_directives($listenPort);

    return <<<CONF
server {
{$listen}
    server_name _;

    include snippets/cloudflare-realip.conf;

    root {$documentRoot};
    index index.php index.html;

    access_log /var/log/nginx/wildcard-php-{$listenPort}-access.log;
    error_log  /var/log/nginx/wildcard-php-{$listenPort}-error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$sock};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) { deny all; }

    include snippets/security-headers.conf;
}
CONF;
}

/**
 * $appPort is the Node.js app's own PM2/proxy target port (unrelated to
 * $listenPort, this wildcard SLOT's dedicated local port) - same
 * multi-slot reasoning as nginx_build_php_wildcard_site_config() above.
 */
function nginx_build_nodejs_wildcard_proxy_config(int $appPort, int $listenPort): string
{
    $listen = nginx_wildcard_listen_directives($listenPort);

    return <<<CONF
server {
{$listen}
    server_name _;

    include snippets/cloudflare-realip.conf;

    access_log /var/log/nginx/wildcard-nodejs-{$listenPort}-access.log;
    error_log  /var/log/nginx/wildcard-nodejs-{$listenPort}-error.log;

    location / {
        proxy_pass http://127.0.0.1:{$appPort};
        include snippets/proxy-params.conf;
    }

    include snippets/security-headers.conf;
}
CONF;
}

/** Shared ssl_certificate/protocol/cipher directives - both PHP site and Node.js proxy configs point at the same Let's Encrypt path convention. */
function nginx_ssl_cert_directives(string $domain): string
{
    return <<<CONF
    ssl_certificate     /etc/letsencrypt/live/{$domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
CONF;
}

/** Companion 80 block for an SSL-enabled domain - keeps ACME renewal reachable and bounces everything else to https. */
function nginx_http_redirect_block(string $domain): string
{
    return <<<CONF
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};
    include snippets/acme-challenge.conf;
    location / {
        return 301 https://\$host\$request_uri;
    }
}
CONF;
}

function nginx_write_config(string $siteName, string $content): array
{
    return Executor::run('nginx-write-config', [$siteName], $content, 20);
}

function nginx_enable_site(string $siteName): array
{
    return Executor::run('nginx-enable', [$siteName], null, 20);
}

function nginx_disable_site(string $siteName): array
{
    return Executor::run('nginx-disable', [$siteName], null, 20);
}

function nginx_delete_site(string $siteName): array
{
    return Executor::run('nginx-delete', [$siteName], null, 20);
}

function nginx_reload(): array
{
    return Executor::run('nginx-reload', [], null, 20);
}
