<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(mixed $token): void
    {
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            throw new \RuntimeException('Your form session expired. Please try again.');
        }
    }
}
