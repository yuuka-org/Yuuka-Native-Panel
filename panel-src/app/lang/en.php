<?php
declare(strict_types=1);

/**
 * English translation of app/lang/id.php. Keep the KEY STRUCTURE
 * identical between every language file - Locale::get() looks up the
 * same dotted path regardless of which file is active, and falls back
 * to this file for anything missing from a non-English locale (see
 * app/helpers/locale.php).
 */
return [
    'common' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'back' => 'Back',
        'yes' => 'Yes',
        'no' => 'No',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'status' => 'Status',
        'action' => 'Action',
        'domain' => 'Domain',
        'loading' => 'Loading...',
        'confirm' => 'Confirm',
        'close' => 'Close',
    ],
    'topbar' => [
        'settings' => 'Settings',
        'logout' => 'Logout',
        'toggle_theme' => 'Toggle dark/light mode',
        'toggle_sidebar' => 'Collapse/expand sidebar',
        'language' => 'Language',
    ],
    'sidebar' => [
        'dashboard' => 'Dashboard',
        'website' => 'Website',
        'app_installer' => 'App Installer',
        'wp_manager' => 'WP Manager',
        'file_manager' => 'File Manager',
        'database' => 'Database',
        'domain' => 'Domain',
        'cron_jobs' => 'Cron Jobs',
        'log' => 'Log',
        'cloudflare_tunnel' => 'Cloudflare Tunnel',
        'system' => 'System',
        'terminal' => 'Terminal',
        'user_management' => 'User Management',
        'settings' => 'Settings',
        'plugin' => 'Plugin',
    ],
    'login' => [
        'subtitle' => 'Sign in to manage your server',
        'username' => 'Username',
        'password' => 'Password',
        'submit' => 'Login',
        'error_required' => 'Username and password are required.',
    ],
    'footer' => [
        'brand' => 'Yuuka Server Panel',
    ],
    'settings' => [
        'default_language' => 'Panel Default Language',
        'default_language_help' => 'Applies to every admin, unless that admin picks their own language via the topbar language switcher.',
    ],
];
