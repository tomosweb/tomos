<?php

declare(strict_types=1);

final class InstallManifestException extends RuntimeException
{
    public function __construct(string $code, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $code;
    }

    private string $errorCode;

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

final class InstallManifest
{
    public const SCHEMA_VERSION = 1;
    public const MAX_ENTRIES = 500;
    public const MAX_FILE_BYTES = 10485760;
    public const MAX_UNCOMPRESSED_BYTES = 104857600;

    private static $requiredFileLists;

    public static function setRequiredFileLists(array $lists): void
    {
        $normalized = [];
        foreach (['distribution', 'installed'] as $label) {
            if (!isset($lists[$label]) || !is_array($lists[$label])) {
                self::fail('required_file', 'Embedded ' . $label . ' file list is invalid.');
            }
            $seen = [];
            foreach ($lists[$label] as $path) {
                if (!is_string($path) || !self::isSafePath($path) || isset($seen[$path])) {
                    self::fail('required_file', 'Embedded ' . $label . ' file list contains an unsafe or duplicate path.');
                }
                $seen[$path] = true;
            }
            $normalized[$label] = array_keys($seen);
        }
        self::$requiredFileLists = $normalized;
    }

    public static function buildFromZip(
        string $zipPath,
        string $versionPath,
        string $assetUrl,
        ?string $expectedVersion = null
    ): array {
        self::requireRuntime();
        if (!is_file($zipPath) || is_link($zipPath) || !is_readable($zipPath)) {
            self::fail('asset_size', 'ZIP file is missing or unreadable.');
        }
        if (!is_file($versionPath) || is_link($versionPath) || !is_readable($versionPath)) {
            self::fail('manifest_version', 'VERSION file is missing or unreadable.');
        }
        $version = trim((string) file_get_contents($versionPath));
        self::validateVersion($version);
        if ($expectedVersion !== null && $expectedVersion !== $version) {
            self::fail('manifest_version', 'Requested version does not match VERSION.');
        }
        self::validateAssetUrl($assetUrl);
        $assetName = basename($zipPath);
        if ($assetName !== 'tomos-' . $version . '.zip') {
            self::fail('manifest_version', 'ZIP filename does not match VERSION.');
        }
        $assetSize = filesize($zipPath);
        $assetHash = hash_file('sha256', $zipPath);
        if ($assetSize === false || !is_string($assetHash)) {
            self::fail('asset_hash', 'Could not hash ZIP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            self::fail('zip_open', 'Could not open ZIP.');
        }
        try {
            $files = self::inventory($zip);
        } finally {
            $zip->close();
        }
        self::validateRequiredFiles(array_keys($files));
        if (!isset($files['VERSION']) || $files['VERSION']['size'] < 1) {
            self::fail('required_file', 'ZIP VERSION is missing.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            self::fail('zip_open', 'Could not reopen ZIP.');
        }
        try {
            $versionContents = $zip->getFromName('VERSION');
        } finally {
            $zip->close();
        }
        if (!is_string($versionContents) || trim($versionContents) !== $version) {
            self::fail('manifest_version', 'ZIP VERSION does not match project VERSION.');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'product' => 'Tomos',
            'version' => $version,
            'asset' => [
                'name' => $assetName,
                'size' => (int) $assetSize,
                'sha256' => strtolower($assetHash),
                'url' => $assetUrl,
            ],
            'limits' => [
                'max_entries' => self::MAX_ENTRIES,
                'max_file_bytes' => self::MAX_FILE_BYTES,
                'max_uncompressed_bytes' => self::MAX_UNCOMPRESSED_BYTES,
            ],
            'files' => $files,
        ];
    }

    public static function encode(array $manifest): string
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            self::fail('manifest_schema', 'Could not encode manifest.');
        }
        return $json . "\n";
    }

    public static function decodeAndValidate(string $raw): array
    {
        if ($raw === '' || !preg_match('//u', $raw)) {
            self::fail('manifest_schema', 'Manifest is not valid UTF-8.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail('manifest_schema', 'Manifest JSON is invalid.');
        }
        self::validateManifest($decoded);
        return $decoded;
    }

    public static function decodePointer(string $raw): array
    {
        if ($raw === '' || !preg_match('//u', $raw)) {
            self::fail('pointer_schema', 'Pointer is not valid UTF-8.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail('pointer_schema', 'Pointer JSON is invalid.');
        }
        self::validatePointer($decoded);
        return $decoded;
    }

    public static function validateManifest(array $manifest): void
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($manifest['product'] ?? null) !== 'Tomos'
            || !is_string($manifest['version'] ?? null)
            || !is_array($manifest['asset'] ?? null)
            || !is_array($manifest['limits'] ?? null)
            || !is_array($manifest['files'] ?? null)
        ) {
            self::fail('manifest_schema', 'Manifest schema is unsupported.');
        }
        self::validateVersion($manifest['version']);
        $asset = $manifest['asset'];
        if (!is_string($asset['name'] ?? null)
            || $asset['name'] !== 'tomos-' . $manifest['version'] . '.zip'
            || !is_int($asset['size'] ?? null) || $asset['size'] < 1
            || !is_string($asset['sha256'] ?? null) || preg_match('/\A[a-f-f0-9]{64}\z/', $asset['sha256']) !== 1
            || !is_string($asset['url'] ?? null)
        ) {
            self::fail('manifest_schema', 'Manifest asset is invalid.');
        }
        self::validateAssetUrl($asset['url']);
        $limits = $manifest['limits'];
        if (($limits['max_entries'] ?? null) !== self::MAX_ENTRIES
            || ($limits['max_file_bytes'] ?? null) !== self::MAX_FILE_BYTES
            || ($limits['max_uncompressed_bytes'] ?? null) !== self::MAX_UNCOMPRESSED_BYTES
        ) {
            self::fail('manifest_schema', 'Manifest limits do not match the supported limits.');
        }
        if ($manifest['files'] === []) {
            self::fail('manifest_schema', 'Manifest file inventory is empty.');
        }
        $paths = [];
        foreach ($manifest['files'] as $path => $file) {
            if (!is_string($path) || !self::isSafePath($path)
                || !is_array($file) || ($file['type'] ?? null) !== 'file'
                || !is_int($file['size'] ?? null) || $file['size'] < 0
                || $file['size'] > self::MAX_FILE_BYTES
                || !is_string($file['sha256'] ?? null)
                || preg_match('/\A[a-f-f0-9]{64}\z/', $file['sha256']) !== 1
            ) {
                self::fail('manifest_schema', 'Manifest file inventory is invalid.');
            }
            if (isset($paths[$path])) {
                self::fail('zip_duplicate', 'Manifest contains a duplicate path.');
            }
            $paths[$path] = true;
        }
        self::validateRequiredFiles(array_keys($paths));
        if (!isset($paths['VERSION'])) {
            self::fail('required_file', 'Manifest VERSION is missing.');
        }
    }

    public static function verifyPackage(string $manifestPath, string $signaturePath, string $zipPath, string $publicKeyPath): array
    {
        self::requireRuntime();
        $raw = @file_get_contents($manifestPath);
        $signature = @file_get_contents($signaturePath);
        $publicKey = @file_get_contents($publicKeyPath);
        if (!is_string($raw) || !is_string($signature) || $signature === '' || !is_string($publicKey)) {
            self::fail('manifest_signature', 'Manifest signature inputs are missing.');
        }
        if (openssl_verify($raw, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            self::fail('manifest_signature', 'Manifest signature verification failed.');
        }
        $manifest = self::decodeAndValidate($raw);
        self::verifyZipAgainstManifest($zipPath, $manifest);
        return $manifest;
    }

    public static function verifyZipAgainstManifest(string $zipPath, array $manifest): void
    {
        self::validateManifest($manifest);
        if (!is_file($zipPath) || is_link($zipPath)) {
            self::fail('asset_size', 'ZIP file is missing.');
        }
        if (basename($zipPath) !== $manifest['asset']['name']) {
            self::fail('manifest_version', 'ZIP filename does not match manifest.');
        }
        $size = filesize($zipPath);
        if ($size === false || (int) $size !== $manifest['asset']['size']) {
            self::fail('asset_size', 'ZIP size does not match manifest.');
        }
        $hash = hash_file('sha256', $zipPath);
        if (!is_string($hash) || !hash_equals(strtolower($manifest['asset']['sha256']), strtolower($hash))) {
            self::fail('asset_hash', 'ZIP hash does not match manifest.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            self::fail('zip_open', 'Could not open ZIP.');
        }
        try {
            $files = self::inventory($zip);
            $expected = $manifest['files'];
            if (array_keys($files) !== array_keys($expected)) {
                self::fail('zip_contents', 'ZIP file inventory does not match manifest.');
            }
            foreach ($expected as $path => $record) {
                if ($files[$path]['size'] !== $record['size']) {
                    self::fail('file_size', 'File size does not match manifest: ' . $path);
                }
                if (!hash_equals(strtolower($record['sha256']), strtolower($files[$path]['sha256']))) {
                    self::fail('file_hash', 'File hash does not match manifest: ' . $path);
                }
            }
        } finally {
            $zip->close();
        }
    }

    public static function extractVerifiedZip(string $zipPath, array $manifest, string $stagingPath): void
    {
        self::verifyZipAgainstManifest($zipPath, $manifest);
        if (file_exists($stagingPath) || is_link($stagingPath) || !@mkdir($stagingPath, 0700, true)) {
            self::fail('extract_write', 'Staging directory is unavailable.');
        }
        @chmod($stagingPath, 0700);
        $stagingReal = realpath($stagingPath);
        if ($stagingReal === false) {
            self::fail('extract_write', 'Staging directory could not be resolved.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            self::fail('zip_open', 'Could not reopen ZIP for extraction.');
        }
        try {
            foreach ($manifest['files'] as $path => $record) {
                if (!self::isSafePath($path)) {
                    self::fail('zip_path', 'Manifest path is unsafe.');
                }
                $destination = $stagingReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                $parent = dirname($destination);
                if (!self::isSafeDestination($stagingReal, $destination) || !self::safeParentChain($stagingReal, $parent)) {
                    self::fail('zip_path', 'Extraction path is outside staging.');
                }
                if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
                    self::fail('extract_write', 'Could not create extraction directory.');
                }
                if (is_link($parent) || is_link($destination)) {
                    self::fail('zip_symlink', 'Extraction path contains a symbolic link.');
                }
                $input = $zip->getStream($path);
                $output = @fopen($destination, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    self::fail('extract_write', 'Could not create extracted file.');
                }
                $context = hash_init('sha256');
                $bytes = 0;
                $ok = true;
                while (!feof($input)) {
                    $chunk = fread($input, 65536);
                    if (!is_string($chunk)) {
                        $ok = false;
                        break;
                    }
                    $length = strlen($chunk);
                    $bytes += $length;
                    if ($bytes > self::MAX_FILE_BYTES || $length > 0 && fwrite($output, $chunk) !== $length) {
                        $ok = false;
                        break;
                    }
                    if ($length > 0) {
                        hash_update($context, $chunk);
                    }
                }
                fclose($input);
                fclose($output);
                if (!$ok) {
                    @unlink($destination);
                    self::fail('extract_write', 'Could not stream extracted file.');
                }
                if ($bytes !== $record['size']) {
                    @unlink($destination);
                    self::fail('file_size', 'Extracted file size does not match manifest: ' . $path);
                }
                if (!hash_equals(strtolower($record['sha256']), strtolower(hash_final($context)))) {
                    @unlink($destination);
                    self::fail('file_hash', 'Extracted file hash does not match manifest: ' . $path);
                }
                @chmod($destination, 0600);
            }
        } finally {
            $zip->close();
        }
        self::validateExtractedFiles($stagingPath, $manifest);
    }

    public static function verifyStagingDirectory(string $stagingPath, array $manifest): void
    {
        self::validateManifest($manifest);
        if (!is_dir($stagingPath) || is_link($stagingPath)) {
            self::fail('placement_verify', 'Verified staging directory is unavailable.');
        }
        self::validateExtractedFiles($stagingPath, $manifest);
    }

    public static function isSafeRelativePath(string $path): bool
    {
        return self::isSafePath($path);
    }

    public static function buildPointer(string $version, string $manifestUrl, string $signatureUrl): array
    {
        self::validateVersion($version);
        self::validatePointerUrl($manifestUrl);
        self::validatePointerUrl($signatureUrl);
        $manifestPath = (string) parse_url($manifestUrl, PHP_URL_PATH);
        $signaturePath = (string) parse_url($signatureUrl, PHP_URL_PATH);
        $expectedManifestPath = '/installer/releases/' . $version . '/install-manifest.json';
        $expectedSignaturePath = '/installer/releases/' . $version . '/install-manifest.sig';
        if ($manifestPath !== $expectedManifestPath
            || $signaturePath !== $expectedSignaturePath
        ) {
            self::fail('pointer_schema', 'Pointer URLs do not match the versioned asset structure.');
        }
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'version' => $version,
            'manifest_url' => $manifestUrl,
            'signature_url' => $signatureUrl,
        ];
    }

    public static function validatePointer(array $pointer): void
    {
        if (($pointer['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($pointer['version'] ?? null)
            || !is_string($pointer['manifest_url'] ?? null)
            || !is_string($pointer['signature_url'] ?? null)
        ) {
            self::fail('pointer_schema', 'Pointer schema is invalid.');
        }
        self::buildPointer($pointer['version'], $pointer['manifest_url'], $pointer['signature_url']);
    }

    public static function encodePointer(array $pointer): string
    {
        self::validatePointer($pointer);
        $json = json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            self::fail('pointer_schema', 'Could not encode pointer.');
        }
        return $json . "\n";
    }

    private static function inventory(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            self::fail('zip_limits', 'ZIP entry count exceeds the supported limit.');
        }
        $files = [];
        $seen = [];
        $normalizedSeen = [];
        $types = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            $rawName = $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED | ZipArchive::FL_ENC_RAW);
            if (!is_array($stat) || !is_string($rawName)) {
                self::fail('zip_path', 'ZIP entry metadata is invalid.');
            }
            $directory = substr($rawName, -1) === '/';
            $path = $directory ? substr($rawName, 0, -1) : $rawName;
            self::validateEntryType($zip, $index, $directory);
            if (!self::isSafePath($path)) {
                self::fail('zip_path', 'ZIP entry path is unsafe.');
            }
            $normalized = strtolower($path);
            if (isset($seen[$path]) || isset($normalizedSeen[$normalized])) {
                self::fail('zip_duplicate', 'ZIP contains duplicate or colliding entries.');
            }
            $segments = explode('/', $path);
            $parent = '';
            foreach ($segments as $position => $segment) {
                if ($position === count($segments) - 1) {
                    break;
                }
                $parent = $parent === '' ? $segment : $parent . '/' . $segment;
                if (($types[$parent] ?? null) === 'file') {
                    self::fail('zip_path', 'ZIP contains a file and child path collision.');
                }
            }
            if (!$directory) {
                foreach ($types as $existingPath => $existingType) {
                    if ($existingType === 'file' && strpos($existingPath, $path . '/') === 0) {
                        self::fail('zip_path', 'ZIP contains a file and directory collision.');
                    }
                }
            }
            $types[$path] = $directory ? 'directory' : 'file';
            $seen[$path] = true;
            $normalizedSeen[$normalized] = true;
            $size = (int) ($stat['size'] ?? -1);
            if ($size < 0) {
                self::fail('zip_contents', 'ZIP entry size is invalid.');
            }
            $compression = (int) ($stat['comp_method'] ?? ZipArchive::CM_STORE);
            if (!in_array($compression, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                self::fail('zip_contents', 'ZIP compression method is unsupported.');
            }
            if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                self::fail('zip_contents', 'Encrypted ZIP entries are unsupported.');
            }
            $total += $size;
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                self::fail('zip_limits', 'ZIP expanded size exceeds the supported limit.');
            }
            if ($directory) {
                continue;
            }
            if ($size > self::MAX_FILE_BYTES) {
                self::fail('zip_limits', 'ZIP file size exceeds the supported limit.');
            }
            $stream = $zip->getStream($rawName);
            if (!is_resource($stream)) {
                self::fail('zip_contents', 'ZIP entry cannot be read.');
            }
            $context = hash_init('sha256');
            $actualSize = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if (!is_string($chunk)) {
                    fclose($stream);
                    self::fail('zip_contents', 'ZIP entry cannot be read.');
                }
                $actualSize += strlen($chunk);
                if ($actualSize > self::MAX_FILE_BYTES) {
                    fclose($stream);
                    self::fail('zip_limits', 'ZIP file size exceeds the supported limit.');
                }
                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }
            fclose($stream);
            if ($actualSize !== $size) {
                self::fail('file_size', 'ZIP entry size does not match its stream.');
            }
            $files[$path] = [
                'type' => 'file',
                'size' => $size,
                'sha256' => hash_final($context),
            ];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function validateEntryType(ZipArchive $zip, int $index, bool $directory): void
    {
        $system = 0;
        $attributes = 0;
        if (!method_exists($zip, 'getExternalAttributesIndex')
            || !$zip->getExternalAttributesIndex($index, $system, $attributes, ZipArchive::FL_UNCHANGED)
            || (int) $system !== ZipArchive::OPSYS_UNIX
        ) {
            return;
        }
        $mode = ($attributes >> 16) & 0170000;
        if ($mode === 0120000) {
            self::fail('zip_symlink', 'ZIP contains a symbolic link.');
        }
        $expected = $directory ? 0040000 : 0100000;
        if ($mode !== 0 && $mode !== $expected) {
            self::fail('zip_path', 'ZIP contains an unsupported entry type.');
        }
    }

    private static function isSafePath(string $path): bool
    {
        return $path !== ''
            && strpos($path, "\0") === false
            && strpos($path, '\\') === false
            && strpos($path, ':') === false
            && $path[0] !== '/'
            && preg_match('#(^|/)\.?\.?(/|$)#', $path) !== 1
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
            && preg_match('//u', $path) === 1;
    }

    private static function isSafeDestination(string $base, string $destination): bool
    {
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return false;
        }
        $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($destination, $basePrefix) === 0;
    }

    private static function safeParentChain(string $base, string $parent): bool
    {
        $base = rtrim((string) realpath($base), DIRECTORY_SEPARATOR);
        $cursor = $parent;
        while ($cursor !== '' && $cursor !== dirname($cursor)) {
            if (is_link($cursor)) {
                return false;
            }
            if ($cursor === $base) {
                return true;
            }
            $cursor = dirname($cursor);
        }
        return false;
    }

    private static function validateExtractedFiles(string $stagingPath, array $manifest): void
    {
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingPath, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->isLink() || !$entry->isFile()) {
                self::fail('extract_write', 'Staging contains an unsafe entry.');
            }
            $actual[str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen(rtrim($stagingPath, DIRECTORY_SEPARATOR)) + 1))] = [
                'size' => (int) $entry->getSize(),
                'sha256' => (string) hash_file('sha256', $entry->getPathname()),
            ];
        }
        ksort($actual, SORT_STRING);
        if (array_keys($actual) !== array_keys($manifest['files'])) {
            self::fail('zip_contents', 'Extracted file inventory does not match manifest.');
        }
        foreach ($manifest['files'] as $path => $record) {
            if ($actual[$path]['size'] !== $record['size'] || !hash_equals(strtolower($actual[$path]['sha256']), strtolower($record['sha256']))) {
                self::fail('file_hash', 'Extracted file verification failed: ' . $path);
            }
        }
        self::validateRequiredFiles(array_keys($actual));
    }

    private static function validateRequiredFiles(array $files): void
    {
        $available = array_fill_keys($files, true);
        $lists = self::$requiredFileLists ?? self::readRequiredFileLists();
        foreach ($lists as $label => $paths) {
            foreach ($paths as $path) {
                if (!isset($available[$path])) {
                    self::fail('required_file', 'Required ' . $label . ' file is missing: ' . $path);
                }
            }
        }
    }

    private static function readRequiredFileLists(): array
    {
        $lists = [];
        foreach ([
            __DIR__ . '/../required-distribution-files.txt' => 'distribution',
            dirname(__DIR__, 2) . '/core/required-installed-files.txt' => 'installed',
        ] as $required => $label) {
            if (!is_file($required) || !is_readable($required)) {
                self::fail('required_file', 'Required ' . $label . ' file list is missing.');
            }
            $lists[$label] = array_values(array_filter(array_map('trim', file($required, FILE_IGNORE_NEW_LINES)), static function (string $path): bool {
                return $path !== '';
            }));
        }
        self::setRequiredFileLists($lists);
        return self::$requiredFileLists;
    }

    private static function validateVersion(string $version): void
    {
        if (preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/', $version) !== 1) {
            self::fail('manifest_version', 'Version is invalid.');
        }
    }

    private static function validateAssetUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            self::fail('manifest_schema', 'Asset URL must be an HTTPS URL without credentials or fragments.');
        }
    }

    private static function validatePointerUrl(string $url): void
    {
        try {
            self::validateAssetUrl($url);
        } catch (InstallManifestException $exception) {
            self::fail('pointer_schema', $exception->getMessage());
        }
    }

    private static function requireRuntime(): void
    {
        if (!class_exists(ZipArchive::class) || !function_exists('openssl_verify')) {
            self::fail('environment', 'ZipArchive and OpenSSL are required.');
        }
    }

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}
