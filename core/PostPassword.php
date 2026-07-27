<?php

declare(strict_types=1);

namespace Tomos;

final class PostPassword
{
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $bytes = random_bytes(24);
        $chars = '';
        $length = strlen($alphabet);

        for ($i = 0; $i < strlen($bytes); $i++) {
            $chars .= $alphabet[ord($bytes[$i]) % $length];
        }

        return 'tms-' . implode('-', str_split($chars, 4));
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($password === '' || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }
}
