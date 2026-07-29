<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.logs.view');
sse_release_session_lock();

$id = (int) ($_GET['id'] ?? 0);
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$app = NodeService::find($id);
if ($app === null) {
    http_response_code(404);
    exit;
}

sse_start();
sse_loop(function () use ($id, &$offset) {
    $tail = NodeService::tailLogs($id, $offset);
    if ($tail['content'] !== '') {
        sse_send('log', ['content' => $tail['content']]);
    }
    if ($tail['offset'] !== $offset) {
        $offset = $tail['offset'];
        sse_send('offset', ['offset' => $offset]);
    }
}, 1500000, 55);
