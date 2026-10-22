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
<html lang="id" data-ui="<?= e(ui_style()) ?>">
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
<?php include __DIR__ . '/head_assets.php'; ?>
</head>
<body class="p-3 embed-page">
<div class="panel-loading-bar" id="panelLoadingBar"></div>
