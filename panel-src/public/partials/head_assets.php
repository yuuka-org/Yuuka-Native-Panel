<?php
/**
 * Stylesheet block shared by every page shell (header.php,
 * embed_header.php, login.php). app.css is always loaded and on its own
 * IS the classic theme, kept untouched; the Graphite layer and its fonts
 * only load on top of it when Settings > Page has Graphite selected (the
 * default) - see ui_style() in app/helpers/response.php.
 */
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if (ui_style() === 'graphite'): ?>
<link href="https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-sans@5.2.8/400.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-sans@5.2.8/500.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-sans@5.2.8/600.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-mono@5.2.7/400.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-mono@5.2.7/500.css" rel="stylesheet">
<?php endif; ?>
<link href="<?= e(asset_url('/assets/css/app.css')) ?>" rel="stylesheet">
<?php if (ui_style() === 'graphite'): ?>
<link href="<?= e(asset_url('/assets/css/graphite.css')) ?>" rel="stylesheet">
<?php endif; ?>
