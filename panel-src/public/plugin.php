<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
// Every plugin page is admin-only, full stop - see PluginService's class
// docblock. An individual plugin's manifest has no way to loosen this;
// there's no meaningful "view-only" access to something that shares a
// process with root-capable code.
Rbac::require('plugin.manage');

$slug = (string) ($_GET['slug'] ?? '');
$route = (string) ($_GET['route'] ?? 'index');

if (!Validator::pluginSlug($slug)) {
    http_response_code(404);
    exit('Plugin tidak ditemukan');
}

$plugin = PluginService::find($slug);
if ($plugin === null || $plugin['manifest'] === null || !$plugin['is_enabled']) {
    http_response_code(404);
    exit('Plugin tidak ditemukan atau tidak aktif');
}

$routeFile = PluginService::resolveRouteFile($plugin, $route);
if ($routeFile === null) {
    http_response_code(404);
    exit('Halaman plugin tidak ditemukan');
}

$pageTitle = (string) ($plugin['manifest']['name'] ?? $slug);
$currentPlugin = $plugin;
// The plugin's own page runs in THIS scope - every panel helper/service
// already loaded by bootstrap.php (Csrf, flash, Rbac, Executor, e(), the
// PluginService::runScript() bridge for its own root-exec scripts, etc.)
// is available to it exactly as if it were core panel code, since it
// genuinely runs with the same trust level as core panel code.
require $routeFile;
