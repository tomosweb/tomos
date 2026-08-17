<?php

declare(strict_types=1);

namespace Tomos;

final class PasskeyCredentialStore
{
    public const SCHEMA_VERSION = 1;

    private string $dir;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, string $rootDir)
    {
        $storageDir = (string) (($config['paths']['storage_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'storage'));
        $this->dir = rtrim($storageDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security'
            . DIRECTORY_SEPARATOR . 'passkeys';
    }

    public function storageDirectory(): string
    {
        return $this->dir;
    }

    /** @param array<string,mixed> $credential */
    public function save(array $credential): bool
    {
        $normalized = $this->normalize($credential);
        if ($normalized === null || !$this->ensureDir()) {
            return false;
        }

        $credentialId = (string) $normalized['credential_id'];
        $path = $this->path($credentialId);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function load(string $credentialId): ?array
    {
        if (!$this->validCredentialId($credentialId)) {
            return null;
        }
        return $this->loadPath($this->path($credentialId));
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $records = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $record = $this->loadPath($file);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        usort($records, static function (array $a, array $b): int {
            return ((int) ($a['created_at'] ?? 0)) <=> ((int) ($b['created_at'] ?? 0));
        });
        return $records;
    }

    public function delete(string $credentialId): bool
    {
        if (!$this->validCredentialId($credentialId)) {
            return false;
        }
        $path = $this->path($credentialId);
        return !is_file($path) || @unlink($path);
    }

    public function updateUsage(string $credentialId, int $signCount, ?int $lastUsedAt = null): bool
    {
        if ($signCount < 0 || !$this->validCredentialId($credentialId) || !$this->ensureDir()) {
            return false;
        }

        $lock = @fopen($this->usageLockPath($credentialId), 'c');
        if (!is_resource($lock)) {
            return false;
        }
        @chmod($this->usageLockPath($credentialId), 0600);
        if (!@flock($lock, LOCK_EX)) {
            fclose($lock);
            return false;
        }

        try {
            // Read only after the per-credential lock is held. This prevents a
            // slower concurrent authentication from overwriting a newer counter.
            $record = $this->load($credentialId);
            if ($record === null) {
                return false;
            }

            $record['sign_count'] = max((int) ($record['sign_count'] ?? 0), $signCount);
            $usedAt = $lastUsedAt ?? time();
            $record['last_used_at'] = max((int) ($record['last_used_at'] ?? 0), $usedAt);
            return $this->save($record);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $credential @return array<string,mixed>|null */
    private function normalize(array $credential): ?array
    {
        $credentialId = trim((string) ($credential['credential_id'] ?? ''));
        $publicKey = trim((string) ($credential['public_key'] ?? ''));
        $rpId = strtolower(trim((string) ($credential['rp_id'] ?? '')));
        $label = trim((string) ($credential['label'] ?? ''));
        $signCount = (int) ($credential['sign_count'] ?? 0);
        $createdAt = (int) ($credential['created_at'] ?? time());
        $lastUsedAt = isset($credential['last_used_at']) ? (int) $credential['last_used_at'] : null;
        $transports = is_array($credential['transports'] ?? null) ? array_values($credential['transports']) : [];

        if (!$this->validCredentialId($credentialId)
            || $publicKey === ''
            || strlen($publicKey) > 32768
            || !$this->validRpId($rpId)
            || $signCount < 0
            || $createdAt <= 0
            || ($lastUsedAt !== null && $lastUsedAt <= 0)
            || strlen($label) > 100
        ) {
            return null;
        }

        $cleanTransports = [];
        foreach ($transports as $transport) {
            $transport = trim((string) $transport);
            if ($transport === '' || strlen($transport) > 32 || preg_match('/\A[a-z0-9_-]+\z/i', $transport) !== 1) {
                continue;
            }
            if (!in_array($transport, $cleanTransports, true)) {
                $cleanTransports[] = $transport;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'credential_id' => $credentialId,
            'public_key' => $publicKey,
            'sign_count' => $signCount,
            'transports' => $cleanTransports,
            'label' => $label,
            'created_at' => $createdAt,
            'last_used_at' => $lastUsedAt,
            'rp_id' => $rpId,
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadPath(string $path): ?array
    {
        $raw = @file_get_contents($path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || (int) ($record['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return null;
        }
        return $this->normalize($record);
    }

    private function path(string $credentialId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $credentialId) . '.json';
    }

    private function usageLockPath(string $credentialId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . '.usage-' . hash('sha256', $credentialId) . '.lock';
    }

    private function validCredentialId(string $credentialId): bool
    {
        if ($credentialId === '' || strlen($credentialId) > 2048) {
            return false;
        }
        return preg_match('/\A[A-Za-z0-9_-]+\z/', $credentialId) === 1;
    }

    private function validRpId(string $rpId): bool
    {
        if ($rpId === '' || strlen($rpId) > 253) {
            return false;
        }
        if (filter_var($rpId, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $rpId) === 1;
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return false;
        }
        @chmod($this->dir, 0700);

        $rules = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($rules)) {
            @file_put_contents($rules, "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
            @chmod($rules, 0600);
        }
        return true;
    }
}
