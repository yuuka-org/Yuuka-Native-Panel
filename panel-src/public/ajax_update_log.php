<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('monitoring.view');

jsonResponse([
    'ok' => true,
    'log' => LogService::selfUpdateLog(300),
    'running' => sys_installer_self_update_status() === 'active',
]);
