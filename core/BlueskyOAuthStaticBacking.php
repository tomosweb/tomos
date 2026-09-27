<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthStaticBacking
{
    private const METADATA_FILENAME = 'oauth-client-metadata.static.json';
    private const JWKS_FILENAME = 'tomos-bluesky-jwks.static.json';

    /**
     * Generate the HTTP-layer backing files from the existing legacy sources.
     * This method intentionally loads, but never creates or changes, the OAuth
     * client key.
     */
    public static function generate(string $rootDir, array $config): void
    {
        $rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $record = (new BlueskyOAuthKeyStore($rootDir))->load();
        if (!is_array($record)) {
            throw new \RuntimeException('Bluesky OAuth client key is unavailable.');
        }

        $metadata = BlueskyOAuthMetadata::clientMetadata($config);
        $publicJwk = $record['public_jwk'] ?? null;
        if (!is_array($publicJwk) || self::containsPrivateMaterial($publicJwk)) {
            throw new \RuntimeException('Bluesky OAuth public JWK is invalid.');
        }
        $jwks = ['keys' => [$publicJwk]];

        self::writeJson($rootDir . DIRECTORY_SEPARATOR . self::METADATA_FILENAME, $metadata);
        self::writeJson($rootDir . DIRECTORY_SEPARATOR . self::JWKS_FILENAME, $jwks);
    }

    /** @param array<string,mixed> $value */
    private static function containsPrivateMaterial(array $value): bool
    {
        foreach ($value as $key => $child) {
            if (in_array(strtolower((string) $key), [
                'private_pem',
                'private_key',
                'd',
                'p',
                'q',
                'dp',
                'dq',
                'qi',
                'access_token',
                'refresh_token',
            ], true)) {
                return true;
            }
            if (is_array($child) && self::containsPrivateMaterial($child)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $document */
    private static function writeJson(string $path, array $document): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Bluesky OAuth static backing target is invalid.');
        }

        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Bluesky OAuth static backing JSON encoding failed.');
        }

        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (@file_put_contents($temporaryPath, $encoded . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Bluesky OAuth static backing could not be written.');
            }
            $readBack = @file_get_contents($temporaryPath);
            $decoded = is_string($readBack) ? json_decode($readBack, true) : null;
            if (!is_array($decoded) || $decoded !== $document) {
                throw new \RuntimeException('Bluesky OAuth static backing JSON validation failed.');
            }
            @chmod($temporaryPath, 0644);
            if (!@rename($temporaryPath, $path)) {
                throw new \RuntimeException('Bluesky OAuth static backing could not be published.');
            }
            @chmod($path, 0644);
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw $exception;
        }
    }
}
