<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$user = Auth::user();
if (!Rbac::can($user['role'], 'website.create') && !Rbac::can($user['role'], 'nodejs.create')) {
    http_response_code(403);
    jsonResponse(['ok' => false, 'error' => 'Tidak diizinkan']);
}

try {
    jsonResponse(['ok' => true, 'entries' => ProjectCloneService::entries((string) ($_GET['src'] ?? ''))]);
} catch (InvalidArgumentException|RuntimeException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
}
