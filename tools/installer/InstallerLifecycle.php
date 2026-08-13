<?php

declare(strict_types=1);

final class InstallerLifecycle
{
    public const DISABLED_SCHEMA_VERSION = 1;

    public function __construct(string $rootDir, string $installerVersion = 'phase4')
    {
        $real = realpath($rootDir);
        if ($real === false || !is_dir($real) || is_link($real)) {
            throw new InstallManifestException('root_fingerprint', 'Installer root is invalid.');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
        $this->management = $this->root . DIRECTORY_SEPARATOR . '.tomos-installer';
        $this->installerVersion = $installerVersion;
    }

    private $root;
    private $management;
    private $installerVersion;

    public function disabledPath(): string
    {
        return $this->management . DIRECTORY_SEPARATOR . 'disabled.json';
    }

    public function isDisabled(): bool
    {
        return is_file($this->disabledPath()) || is_link($this->disabledPath());
    }

    public function disable(array $installed): void
    {
        if (!is_dir($this->management) && !@mkdir($this->management, 0700, true)) {
            throw new InstallManifestException('disable_marker', 'Could not create installer management directory.');
        }
        if (is_link($this->disabledPath())) {
            throw new InstallManifestException('disable_marker', 'Installer disable marker is unsafe.');
        }
        $data = [
            'schema_version' => self::DISABLED_SCHEMA_VERSION,
            'disabled_at' => gmdate(DATE_ATOM),
            'tomos_version' => (string) ($installed['version'] ?? ''),
            'transaction_id' => (string) ($installed['transaction_id'] ?? ''),
            'reason' => 'installed',
            'installer_version' => $this->installerVersion,
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = $this->disabledPath() . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle) || fwrite($handle, $json) === false || !fflush($handle)) {
            if (is_resource($handle)) fclose($handle);
            @unlink($temporary);
            throw new InstallManifestException('disable_marker', 'Could not write installer disable marker.');
        }
        fclose($handle);
        if (!@rename($temporary, $this->disabledPath())) {
            @unlink($temporary);
            throw new InstallManifestException('disable_marker', 'Could not activate installer disable marker.');
        }
        @chmod($this->disabledPath(), 0600);
    }

    public function trySelfDelete(string $installerPath, bool $enabled = true): bool
    {
        if (!$enabled) return false;
        $real = realpath($installerPath);
        if ($real === false || is_link($installerPath) || !is_file($real)) return false;
        if (basename($real) !== 'install.php') return false;
        $root = realpath($this->root);
        if ($root === false || dirname($real) !== $root) return false;
        return @unlink($real);
    }

    public function completionTarget(string $mode, ?string $child = null): string
    {
        if ($mode === InstallerPlacement::MODE_CHILD) {
            if (!is_string($child) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $child) !== 1) {
                throw new InstallManifestException('target_exists', 'Child directory name is invalid.');
            }
            return './' . $child . '/setup/';
        }
        return './setup/';
    }
}
