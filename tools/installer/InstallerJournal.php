<?php

declare(strict_types=1);

final class InstallerJournal
{
    public const SCHEMA_VERSION = 1;

    public function __construct(string $root, string $transactionId)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        if (!preg_match('/\A[a-f0-9]{32}\z/', $transactionId)) {
            throw new InstallManifestException('transaction_create', 'Transaction ID is invalid.');
        }
        $this->directory = $this->root . DIRECTORY_SEPARATOR . '.tomos-installer' . DIRECTORY_SEPARATOR . 'transactions' . DIRECTORY_SEPARATOR . $transactionId;
        $this->path = $this->directory . DIRECTORY_SEPARATOR . 'journal.json';
    }

    private string $directory;
    private string $path;
    private string $root;

    public function create(array $data): void
    {
        if (file_exists($this->directory) || is_link($this->directory) || !@mkdir($this->directory, 0700, true)) {
            throw new InstallManifestException('transaction_create', 'Could not create transaction directory.');
        }
        $this->write($data);
    }

    public function write(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new InstallManifestException('transaction_write', 'Could not encode transaction journal.');
        }
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new InstallManifestException('transaction_write', 'Could not create transaction journal temporary file.');
        }
        $ok = fwrite($handle, $json . "\n") !== false && fflush($handle);
        if ($ok && function_exists('fsync')) {
            $ok = fsync($handle);
        }
        fclose($handle);
        if (!$ok || !@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new InstallManifestException('transaction_write', 'Could not atomically update transaction journal.');
        }
        @chmod($this->path, 0600);
    }

    public function read(): array
    {
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new InstallManifestException('recovery_unsafe', 'Transaction journal is corrupt.');
        }
        return $data;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function removeIfSafe(): void
    {
        if (is_link($this->directory)) {
            throw new InstallManifestException('recovery_unsafe', 'Transaction directory is a symlink.');
        }
        $this->removeTree($this->directory);
    }

    public static function rootFingerprint(string $root): string
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real) || is_link($real)) {
            throw new InstallManifestException('root_fingerprint', 'Installer root cannot be resolved.');
        }
        $stat = @stat($real);
        $identity = $real . '|' . (is_array($stat) ? (string) ($stat['dev'] ?? '') : '');
        return hash('sha256', $identity);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
