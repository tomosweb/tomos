<?php

declare(strict_types=1);

final class InstallerCore
{
    public const DEFAULT_POINTER_URL = 'https://tomoswords.org/installer/latest.json';
    public const MIN_PHP_VERSION = '7.4.0';

    public function __construct(string $rootDir, array $config = [], ?InstallerDownloader $downloader = null)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->pointerUrl = (string) ($config['pointer_url'] ?? self::DEFAULT_POINTER_URL);
        $this->pointerHosts = (array) ($config['pointer_hosts'] ?? ['tomoswords.org']);
        $this->manifestHosts = (array) ($config['manifest_hosts'] ?? $this->pointerHosts);
        $this->assetHosts = (array) ($config['asset_hosts'] ?? $this->manifestHosts);
        $this->publicKey = (string) ($config['public_key'] ?? InstallerPublicKey::pem());
        $this->downloader = $downloader ?? new InstallerDownloader();
        $this->https = (bool) ($config['https'] ?? true);
    }

    private $rootDir;
    private $pointerUrl;
    private $pointerHosts;
    private $manifestHosts;
    private $assetHosts;
    private $publicKey;
    private $downloader;
    private $https;

    public function diagnostics(): array
    {
        $errors = [];
        $warnings = [];
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $errors[] = ['code' => 'environment', 'message' => 'PHP version is below the Tomos minimum.'];
        }
        if (!$this->https) {
            $errors[] = ['code' => 'environment', 'message' => 'HTTPS is required.'];
        }
        foreach ([['openssl_verify', function_exists('openssl_verify')], ['random_bytes', function_exists('random_bytes')], ['session', function_exists('session_start')], ['flock', function_exists('flock')]] as $check) {
            if (!$check[1]) {
                $errors[] = ['code' => 'environment', 'message' => $check[0] . ' is unavailable.'];
            }
        }
        if (!class_exists(ZipArchive::class)) {
            $errors[] = ['code' => 'environment', 'message' => 'ZipArchive is unavailable.'];
        }
        if (!function_exists('curl_init') && !(bool) ini_get('allow_url_fopen')) {
            $errors[] = ['code' => 'environment', 'message' => 'No HTTPS downloader is available.'];
        }
        if (!is_dir($this->rootDir) || !is_writable($this->rootDir)) {
            $errors[] = ['code' => 'staging_create', 'message' => 'Installer root is not writable.'];
        }
        if (@disk_free_space($this->rootDir) === false) {
            $warnings[] = ['code' => 'disk_free_space', 'message' => 'Free disk space could not be determined.'];
        }
        $maxExecution = (string) ini_get('max_execution_time');
        if ($maxExecution !== '' && ctype_digit($maxExecution) && (int) $maxExecution > 0 && (int) $maxExecution < InstallerDownloader::TOTAL_TIMEOUT) {
            $warnings[] = ['code' => 'max_execution_time', 'message' => 'PHP execution time is below the downloader timeout.'];
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function bootstrap(): array
    {
        InstallerSecurity::startSession($this->https);
        return InstallerSecurity::bootstrap();
    }

    public function csrfToken(): string
    {
        return InstallerSecurity::csrfToken();
    }

    public function prepare(string $csrfToken): array
    {
        $diagnostics = $this->diagnostics();
        if ($diagnostics['errors'] !== []) {
            self::fail('environment', 'Installer diagnostics failed.');
        }
        InstallerSecurity::assertPostOwner($csrfToken);
        $staging = new InstallerStaging($this->rootDir);
        $work = $staging->createWork();
        $lock = null;
        try {
            $lock = $this->acquireLock($staging);
            $pointerPath = $work['downloads'] . DIRECTORY_SEPARATOR . 'latest.json';
            $this->download($this->pointerUrl, $pointerPath, InstallerDownloader::POINTER_MAX_BYTES, $this->pointerHosts, 'pointer_download');
            $pointer = InstallManifest::decodePointer((string) file_get_contents($pointerPath));
            $this->assertAllowedUrl($pointer['manifest_url'], $this->manifestHosts, 'manifest_download');
            $this->assertAllowedUrl($pointer['signature_url'], $this->manifestHosts, 'signature_download');

            $manifestPath = $work['downloads'] . DIRECTORY_SEPARATOR . 'install-manifest.json';
            $signaturePath = $work['downloads'] . DIRECTORY_SEPARATOR . 'install-manifest.sig';
            $this->download($pointer['manifest_url'], $manifestPath, InstallerDownloader::MANIFEST_MAX_BYTES, $this->manifestHosts, 'manifest_download');
            $this->download($pointer['signature_url'], $signaturePath, InstallerDownloader::SIGNATURE_MAX_BYTES, $this->manifestHosts, 'signature_download');
            $manifestRaw = (string) file_get_contents($manifestPath);
            $signature = (string) file_get_contents($signaturePath);
            if (openssl_verify($manifestRaw, $signature, $this->publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                self::fail('manifest_signature', 'Manifest signature verification failed.');
            }
            $manifest = InstallManifest::decodeAndValidate($manifestRaw);
            if ($manifest['version'] !== $pointer['version']) {
                self::fail('manifest_version', 'Pointer and manifest versions do not match.');
            }
            $this->assertAllowedUrl($manifest['asset']['url'], $this->assetHosts, 'asset_host');
            $assetName = $manifest['asset']['name'];
            $zipPath = $work['downloads'] . DIRECTORY_SEPARATOR . $assetName;
            $download = $this->download($manifest['asset']['url'], $zipPath, InstallerDownloader::ZIP_MAX_BYTES, $this->assetHosts, 'asset_download');
            if ($download['size'] !== $manifest['asset']['size']) {
                self::fail('asset_size', 'Downloaded ZIP size does not match manifest.');
            }
            InstallManifest::verifyZipAgainstManifest($zipPath, $manifest);
            InstallManifest::extractVerifiedZip($zipPath, $manifest, $work['staging']);
            return [
                'verified_staging_path' => $work['staging'],
                'manifest' => $manifest,
                'version' => $manifest['version'],
                'transaction_candidate_id' => $work['id'],
                'installer_root' => $this->rootDir,
                'selected_mode' => null,
                'child_directory' => null,
                'verification_completed' => true,
                'work_path' => $work['work'],
            ];
        } catch (Throwable $exception) {
            $staging->cleanup($work['work']);
            if ($exception instanceof InstallManifestException) {
                throw $exception;
            }
            self::fail('cleanup', 'Installer preparation failed.');
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function cleanup(string $workPath): void
    {
        (new InstallerStaging($this->rootDir))->cleanup($workPath);
    }

    private function download(string $url, string $destination, int $maxBytes, array $hosts, string $code): array
    {
        try {
            return $this->downloader->download($url, $destination, $maxBytes, $hosts, $code);
        } catch (InstallManifestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            self::fail($code, 'Download failed.');
        }
    }

    private function assertAllowedUrl(string $url, array $hosts, string $code): void
    {
        InstallerSecurity::validateUrl($url, $hosts, $code);
    }

    private function acquireLock(InstallerStaging $staging)
    {
        $base = rtrim($this->rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.tomos-installer';
        $path = $base . DIRECTORY_SEPARATOR . 'install.lock';
        if (is_link($path)) {
            self::fail('lock', 'Installer lock path is unsafe.');
        }
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            self::fail('lock', 'Another installer request is active.');
        }
        @chmod($path, 0600);
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}
