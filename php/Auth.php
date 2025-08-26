<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private const SESSION_FLAG = 'employee';
    private const SESSION_TS   = 'employee_login_at';

    public static function loginOrFail(string $pin): void
    {
        Csrf::ensureSession();
        $validPin = $_ENV['EMPLOYEE_PIN'] ?? '';
        if ($pin === '' || !hash_equals($validPin, $pin)) {
            \App\jsonResponse(['error' => 'PIN ungültig'], 403);
        }
        $_SESSION[self::SESSION_FLAG] = true;
        $_SESSION[self::SESSION_TS]   = time();
    }

    public static function requireEmployee(): void
    {
        Csrf::ensureSession();
        if (empty($_SESSION[self::SESSION_FLAG])) {
            \App\jsonResponse(['error' => 'Nicht eingeloggt'], 403);
        }
    }

    public static function logout(): void
    {
        Csrf::ensureSession();
        unset($_SESSION[self::SESSION_FLAG], $_SESSION[self::SESSION_TS]);
    }
}
