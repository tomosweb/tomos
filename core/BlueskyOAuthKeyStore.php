<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthKeyStore
{
    private string $dir;
    private string $path;

    public function __construct(string $rootDir)
    {
        $this->dir = rtrim($rootDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'social'
            . DIRECTORY_SEPARATOR . 'oauth';
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'bluesky-client-key.json';
    }

    /** @return array<string,mixed>|null */
    public function loadOrCreate(): ?array
    {
        $existing = $this->load();
        if ($existing !== null) {
            return $existing;
        }
        if (!$this->ensureDir() || !function_exists('openssl_pkey_new')) {
            return null;
        }

        $resource = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($resource === false) {
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || !is_array($details['ec'] ?? null)) {
            return null;
        }

        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem) || $privatePem === '') {
            return null;
        }

        $ec = $details['ec'];
        $x = is_string($ec['x'] ?? null) ? $ec['x'] : '';
        $y = is_string($ec['y'] ?? null) ? $ec['y'] : '';
        if ($x === '' || $y === '') {
            return null;
        }

        $record = [
            'kid' => bin2hex(random_bytes(8)),
            'alg' => 'ES256',
            'private_pem' => $privatePem,
            'public_jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'alg' => 'ES256',
                'use' => 'sig',
                'x' => self::base64Url($x),
                'y' => self::base64Url($y),
            ],
            'created_at' => gmdate('c'),
        ];
        $record['public_jwk']['kid'] = $record['kid'];

        return $this->write($record) ? $record : null;
    }

    /** @return array<string,mixed>|null */
    public function load(): ?array
    {
        $raw = @file_get_contents($this->path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)
            || !is_string($record['kid'] ?? null)
            || !is_string($record['private_pem'] ?? null)
            || !is_array($record['public_jwk'] ?? null)
        ) {
            return null;
        }
        return $record;
    }

    private function write(array $record): bool
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }
        $tmp = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($this->path, 0600);
        return true;
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }
        $rules = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($rules)) {
            @file_put_contents($rules, "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
        }
        return true;
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
