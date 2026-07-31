<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
// Deliberately NOT Auth::requireLogin() - the language switcher is also
// shown on the login page itself, before any session exists yet. Setting
// a locale preference is harmless regardless of auth state.
Csrf::validateRequest();

Locale::setSessionLocale((string) ($_POST['locale'] ?? ''));

$back = (string) ($_POST['back'] ?? '');
$fallback = Auth::check() ? '/dashboard' : '/login';
redirect(Auth::isSafeRedirectTarget($back) && $back !== '' ? $back : $fallback);
