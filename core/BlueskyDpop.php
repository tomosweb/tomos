<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyDpop
{
    /** @param array<string,mixed> $publicJwk */
    public static function proof(
        string $method,
        string $url,
        string $privatePem,
        array $publicJwk,
        ?string $nonce = null,
        ?string $accessToken = null
    ): string {
        $htu = self::normalizedHtu($url);
        $claims = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => $htu,
            'iat' => time(),
        ];
        if ($nonce !== null && $nonce !== '') {
            $claims['nonce'] = $nonce;
        }
        if ($accessToken !== null && $accessToken !== '') {
            $claims['ath'] = Es256Jwt::base64Url(hash('sha256', $accessToken, true));
        }

        return Es256Jwt::sign([
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => $publicJwk,
        ], $claims, $privatePem);
    }

    private static function normalizedHtu(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
        ) {
            throw new \InvalidArgumentException('DPoP URL must be HTTPS.');
        }

        $normalized = 'https://' . strtolower((string) $parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= (string) ($parts['path'] ?? '/');
        return $normalized;
    }
}
