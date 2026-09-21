<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthSessionKey
{
    /** @return array<string,mixed> */
    public static function generate(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('Bluesky OAuth requires the OpenSSL extension.');
        }

        $resource = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($resource === false) {
            throw new \RuntimeException('DPoP key could not be generated.');
        }

        $details = openssl_pkey_get_details($resource);
        $privatePem = '';
        if (!is_array($details)
            || !is_array($details['ec'] ?? null)
            || !openssl_pkey_export($resource, $privatePem)
        ) {
            throw new \RuntimeException('DPoP key could not be exported.');
        }

        $ec = $details['ec'];
        $x = is_string($ec['x'] ?? null) ? $ec['x'] : '';
        $y = is_string($ec['y'] ?? null) ? $ec['y'] : '';
        if ($x === '' || $y === '' || $privatePem === '') {
            throw new \RuntimeException('DPoP key details are incomplete.');
        }

        return [
            'private_pem' => $privatePem,
            'public_jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x' => self::base64Url($x),
                'y' => self::base64Url($y),
            ],
        ];
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
