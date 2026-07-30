<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

/**
 * Unauthenticated plugin API dispatcher - for EXTERNAL callers (a WHMCS
 * provisioning module, a webhook, etc.) that have no panel login session
 * of their own. Deliberately does NOT call Auth::requireLogin() or
 * Csrf::validateRequest() - a plugin's api_routes file is fully
 * responsible for authenticating its own caller (e.g. checking a
 * shared-secret header) before doing anything privileged. See
 * public/plugin.php for the SEPARATE, admin-session-gated dispatcher
 * that "routes" (as opposed to "api_routes") resolve through - the two
 * manifest keys are kept distinct specifically so a plugin author can
 * never mix up which of their files ends up reachable without a login.
 */

$slug = (string) ($_GET['slug'] ?? '');
$route = (string) ($_GET['route'] ?? '');

if (!Validator::pluginSlug($slug)) {
    http_response_code(404);
    exit;
}

$plugin = PluginService::find($slug);
if ($plugin === null || $plugin['manifest'] === null || !$plugin['is_enabled']) {
    http_response_code(404);
    exit;
}

$routeFile = PluginService::resolveApiRouteFile($plugin, $route);
if ($routeFile === null) {
    http_response_code(404);
    exit;
}

$currentPlugin = $plugin;
require $routeFile;
