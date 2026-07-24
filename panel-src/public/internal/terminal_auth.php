<?php
declare(strict_types=1);

/**
 * Nginx auth_request target ONLY (see modules/terminal.sh's generated
 * snippet: `location = /internal/terminal_auth.php { internal; ... }`).
 * Never reachable directly by a browser - Nginx's `internal;` directive
 * plus the panel vhost's own `location ~* ^/(...|internal)/ { deny all; }`
 * both block that. Body is irrelevant; Nginx only inspects the status
 * code (200 = allow the WebSocket proxy through to ttyd, 401/403 = deny).
 */
require __DIR__ . '/../../bootstrap.php';

if (!Auth::check()) {
    http_response_code(401);
    exit;
}

$user = Auth::user();
if (!Rbac::can($user['role'] ?? '', 'terminal.access')) {
    http_response_code(403);
    exit;
}

http_response_code(200);
