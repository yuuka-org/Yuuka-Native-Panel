<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

// Backup moved under Settings > Backup & Restore (creating a backup is now
// a per-item action on Website PHP / Node.js Apps / Database instead of its
// own sidebar menu) - this stub just carries old bookmarks/links forward,
// including a direct ?download=<id> link.
$query = $_SERVER['QUERY_STRING'] ?? '';
redirect('/settings_backup.php' . ($query !== '' ? '?' . $query : ''));
