<?php

declare(strict_types=1);

final class InstallerStaging
{
    public function __construct(string $rootDir)
    {
        $this->baseDir = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.tomos-installer';
    }

    private $baseDir;

    public function createWork(): array
    {
        if (is_link($this->baseDir) || (file_exists($this->baseDir) && !is_dir($this->baseDir))) {
            self::fail('staging_create', 'Installer working area is unsafe.');
        }
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0700, true)) {
            self::fail('staging_create', 'Could not create installer working area.');
        }
        @chmod($this->baseDir, 0700);
        $this->writeDenyFile();
        $id = bin2hex(random_bytes(16));
        $work = $this->baseDir . DIRECTORY_SEPARATOR . 'work-' . $id;
        if (!@mkdir($work, 0700) || is_link($work)) {
            self::fail('staging_create', 'Could not create unique installer work area.');
        }
        $downloads = $work . DIRECTORY_SEPARATOR . 'downloads';
        $staging = $work . DIRECTORY_SEPARATOR . 'staging';
        if (!@mkdir($downloads, 0700)) {
            $this->cleanup($work);
            self::fail('staging_create', 'Could not create installer staging directories.');
        }
        return ['id' => $id, 'work' => $work, 'downloads' => $downloads, 'staging' => $staging];
    }

    public function cleanup(string $work): void
    {
        $base = realpath($this->baseDir);
        $realWork = realpath($work);
        if ($base === false || $realWork === false || strpos($realWork, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }
        $this->removeTree($realWork);
    }

    private function writeDenyFile(): void
    {
        $deny = $this->baseDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (file_exists($deny) || is_link($deny)) {
            return;
        }
        $handle = @fopen($deny, 'x');
        if (!is_resource($handle)) {
            self::fail('staging_create', 'Could not protect installer working area.');
        }
        fwrite($handle, "Require all denied\nDeny from all\n");
        fclose($handle);
        @chmod($deny, 0600);
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

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}
