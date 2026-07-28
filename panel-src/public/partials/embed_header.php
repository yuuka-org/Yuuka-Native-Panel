<?php
/**
 * Minimal page shell for content meant to be loaded inside an iframe
 * (e.g. Node.js Apps' Settings popup, see nodejs.php) - no app-shell
 * sidebar/topbar, since that chrome would be nested/redundant inside a
 * modal that's already floating over the real one. Same theme-sync
 * script as the real header.php so dark/light mode still matches.
 * @var string $pageTitle
 */
$pageTitle = $pageTitle ?? 'Yuuka Panel';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<script>
(function () {
  var theme = localStorage.getItem('yuuka-theme') || 'light';
  document.documentElement.setAttribute('data-bs-theme', theme);
})();
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= e(asset_url('/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="p-3">
<div class="panel-loading-bar" id="panelLoadingBar"></div>
