<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validateRequest();
}
Auth::logout();
redirect('/login');
