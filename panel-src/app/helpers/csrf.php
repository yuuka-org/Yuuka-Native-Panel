<?php
declare(strict_types=1);

final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function validateRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $submitted = $_POST['_csrf'] ?? '';
        $expected = $_SESSION[self::SESSION_KEY] ?? '';

        if ($expected === '' || !is_string($submitted) || !hash_equals($expected, $submitted)) {
            http_response_code(419);
            flash('error', 'Sesi kedaluwarsa atau token keamanan tidak valid. Silakan ulangi.');
            redirect('/dashboard');
        }
    }
}
