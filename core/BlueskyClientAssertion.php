<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyClientAssertion
{
    /** @param array<string,mixed> $keyRecord */
    public static function create(string $clientId, string $audience, array $keyRecord): string
    {
        $kid = (string) ($keyRecord['kid'] ?? '');
        $privatePem = (string) ($keyRecord['private_pem'] ?? '');
        if ($kid === '' || $privatePem === '') {
            throw new \RuntimeException('OAuth client key is unavailable.');
        }

        $now = time();
        return Es256Jwt::sign([
            'typ' => 'JWT',
            'alg' => 'ES256',
            'kid' => $kid,
        ], [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $audience,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + 60,
        ], $privatePem);
    }
}
