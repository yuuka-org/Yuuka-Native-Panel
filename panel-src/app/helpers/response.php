<?php
declare(strict_types=1);

/** Escape for safe HTML output. Use on every piece of user/DB-sourced text. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Appends a `?v=<mtime>` cache-buster to a same-origin static asset path
 * (e.g. '/assets/css/app.css') - without this, an updated CSS/JS file
 * deployed to the server keeps getting served from the browser's (or,
 * behind Cloudflare, the EDGE's) cache under the same unchanged URL,
 * silently showing a stale/broken layout after every deploy until the
 * cache happens to expire or someone manually purges it. Falls back to
 * the bare path if the file can't be stat'd (e.g. never happens in
 * practice, but a 404 asset shouldn't crash page rendering over this).
 */
function asset_url(string $relPath): string
{
    $absPath = APP_PATH . '/public' . $relPath;
    $mtime = @filemtime($absPath);
    return $mtime !== false ? $relPath . '?v=' . $mtime : $relPath;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int, array{type:string,message:string}> */
function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function currentPath(): string
{
    return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

/**
 * Detects the scheme (http/https) the visitor actually used, even when
 * TLS is terminated upstream of this process (e.g. Cloudflare) and PHP
 * itself only ever sees plain HTTP - the panel's own Nginx vhost only
 * ever `listen 80`. Checks Cloudflare's own always-sent CF-Visitor
 * header first (present regardless of which Cloudflare SSL mode is
 * active, no extra Cloudflare-side config needed), then the more
 * generic X-Forwarded-Proto a different reverse proxy might set, then
 * finally $_SERVER['HTTPS'] for a direct/no-proxy TLS termination.
 * Trusted unconditionally (unlike Auth::clientIp(), which only trusts
 * CF-Connecting-IP from IPs Nginx already verified are Cloudflare's) -
 * the only thing this ever decides is which scheme to put in a
 * convenience redirect URL, not an auth/access decision, so a spoofed
 * header at worst breaks that one redirect for whoever sent it.
 */
function currentScheme(): string
{
    $cfVisitor = $_SERVER['HTTP_CF_VISITOR'] ?? '';
    if ($cfVisitor !== '') {
        $decoded = json_decode($cfVisitor, true);
        if (is_array($decoded) && ($decoded['scheme'] ?? '') === 'https') {
            return 'https';
        }
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return 'https';
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    return 'http';
}
