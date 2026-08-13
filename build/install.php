<?php

declare(strict_types=1);


/* BEGIN tools/installer/InstallManifest.php */
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
        if (strpos($manifestPath, '/v' . $version . '/') === false
            || strpos($signaturePath, '/v' . $version . '/') === false
            || substr($manifestPath, -strlen('/install-manifest.json')) !== '/install-manifest.json'
            || substr($signaturePath, -strlen('/install-manifest.sig')) !== '/install-manifest.sig'
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
        self::fail('required_file', 'Embedded required file lists are unavailable.');
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

/* END tools/installer/InstallManifest.php */

/* BEGIN tools/installer/InstallerPublicKey.php */
final class InstallerPublicKey
{
    public const PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAt82VnNFq8Yyh04gDBAY3
wAqijamjL50OcNpMUTeyf3qv7VQlNcjLQXdN+SPP0YxtrMieINwgflL9LHny59HF
7+WekfvFRTsZVJ8ip8UVlKZxkeoKmiZC89qdE4JyHlZ/hmZA9MhTBfL6oEUnkmPT
k+CZXrb8CiTKQS70ohvxVG/0OSrjha4F3D3VJfTxdLQiDoPDaAC/tbXm77258VDb
+tCy82GUG3I7rf+3GbCpAaxBMtQRwy948KJ2O8jOjQ+5s1Ml+eYERtEQL40MiH+l
xc7XTbRbKlmuyPNHkIJT86omedP7dgd7SaQCuZe0T19lsJMhswl9nUlL0kIpBpdL
5Rxc3r0q0YdLOQ8s1KNkMJSGM/rNwJyX64i9yZK9FO0vNcV9JmcrDiIzqn0VuFSs
HUTvbDS6JWbX9ZW25gachZ/r+h7K4x1u64T3ka39FcBOSrXMHD0Hdu7Z48ejkEm8
egJvcwfGEHUO037IPk2ji4d0dGtHsb1O/gMyTilT3sQzAgMBAAE=
-----END PUBLIC KEY-----
PEM;

    public static function pem(): string
    {
        return self::PEM . "\n";
    }
}

/* END tools/installer/InstallerPublicKey.php */

/* BEGIN tools/installer/InstallerSecurity.php */
final class InstallerSecurity
{
    public const BOOTSTRAP_TTL = 900;
    private const SESSION_BOOTSTRAP = 'tomos_installer_bootstrap';
    private const SESSION_CSRF = 'tomos_installer_csrf';

    public static function startSession(bool $https = true): void
    {
        if (!$https) {
            self::fail('session', 'HTTPS is required.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        if (!@session_start([
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ])) {
            self::fail('session', 'Could not start session.');
        }
    }

    public static function bootstrap(int $now = 0): array
    {
        self::requireSession();
        $now = $now > 0 ? $now : time();
        $state = $_SESSION[self::SESSION_BOOTSTRAP] ?? null;
        if (!is_array($state) || !is_string($state['id'] ?? null) || (int) ($state['expires_at'] ?? 0) < $now) {
            if (!@session_regenerate_id(true)) {
                self::fail('session', 'Could not rotate session ID.');
            }
            $state = [
                'id' => bin2hex(random_bytes(32)),
                'created_at' => $now,
                'expires_at' => $now + self::BOOTSTRAP_TTL,
            ];
            $_SESSION[self::SESSION_BOOTSTRAP] = $state;
        }
        if (!isset($_SESSION[self::SESSION_CSRF]) || !is_string($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return $state;
    }

    public static function csrfToken(): string
    {
        self::requireSession();
        $token = $_SESSION[self::SESSION_CSRF] ?? '';
        if (!is_string($token) || $token === '') {
            self::fail('csrf', 'CSRF token is unavailable.');
        }
        return $token;
    }

    public static function assertPostOwner(string $token, int $now = 0): void
    {
        self::requireSession();
        $now = $now > 0 ? $now : time();
        $state = $_SESSION[self::SESSION_BOOTSTRAP] ?? null;
        if (!is_array($state) || !is_string($state['id'] ?? null) || (int) ($state['expires_at'] ?? 0) < $now) {
            self::fail('bootstrap_owner', 'Installer bootstrap ownership is missing or expired.');
        }
        $csrf = $_SESSION[self::SESSION_CSRF] ?? '';
        if (!is_string($csrf) || $token === '' || !hash_equals($csrf, $token)) {
            self::fail('csrf', 'CSRF validation failed.');
        }
    }

    public static function validateUrl(string $url, array $allowedHosts, string $errorCode): void
    {
        if (strlen($url) > 2048) {
            self::fail($errorCode, 'URL is too long.');
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])
            || ($port !== null && (int) $port !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !in_array($host, array_map('strtolower', $allowedHosts), true)
        ) {
            self::fail($errorCode, 'URL is not allowed.');
        }
    }

    private static function requireSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::fail('session', 'Session is not active.');
        }
    }

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}

/* END tools/installer/InstallerSecurity.php */

/* BEGIN tools/installer/InstallerDownloader.php */
final class InstallerDownloader
{
    public const POINTER_MAX_BYTES = 65536;
    public const MANIFEST_MAX_BYTES = 2097152;
    public const SIGNATURE_MAX_BYTES = 16384;
    public const ZIP_MAX_BYTES = 52428800;
    public const CONNECT_TIMEOUT = 10;
    public const TOTAL_TIMEOUT = 120;
    public const MAX_REDIRECTS = 3;

    public function __construct(?callable $fixtureTransport = null)
    {
        $this->fixtureTransport = $fixtureTransport;
    }

    private $fixtureTransport;

    public function download(string $url, string $destination, int $maxBytes, array $allowedHosts, string $errorCode): array
    {
        $current = $url;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            InstallerSecurity::validateUrl($current, $allowedHosts, $errorCode);
            @unlink($destination);
            $result = $this->fixtureTransport !== null
                ? $this->fixture($current, $destination, $maxBytes)
                : $this->production($current, $destination, $maxBytes);
            $status = (int) ($result['status'] ?? 0);
            if ($status >= 300 && $status < 400 && isset($result['location'])) {
                if ($redirect >= self::MAX_REDIRECTS) {
                    @unlink($destination);
                    self::fail('asset_download', 'Redirect limit exceeded.');
                }
                $current = self::resolveUrl($current, (string) $result['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                @unlink($destination);
                self::fail($errorCode, 'HTTP download failed.');
            }
            $size = filesize($destination);
            if ($size === false || $size > $maxBytes) {
                @unlink($destination);
                self::fail($errorCode, 'Downloaded response exceeds the hard limit.');
            }
            if (isset($result['content_length']) && (int) $result['content_length'] !== (int) $size) {
                @unlink($destination);
                self::fail('asset_size', 'Content-Length does not match downloaded bytes.');
            }
            return ['url' => $current, 'status' => $status, 'size' => (int) $size, 'headers' => $result['headers'] ?? []];
        }
        self::fail($errorCode, 'Download failed.');
    }

    private function fixture(string $url, string $destination, int $maxBytes): array
    {
        $result = call_user_func($this->fixtureTransport, $url, $destination, $maxBytes);
        if (!is_array($result)) {
            self::fail('asset_download', 'Fixture transport returned an invalid result.');
        }
        return $result;
    }

    private function production(string $url, string $destination, int $maxBytes): array
    {
        if (function_exists('curl_init')) {
            return $this->curl($url, $destination, $maxBytes);
        }
        if ((bool) ini_get('allow_url_fopen')) {
            return $this->stream($url, $destination, $maxBytes);
        }
        self::fail('environment', 'Neither cURL nor allow_url_fopen is available.');
    }

    private function curl(string $url, string $destination, int $maxBytes): array
    {
        $handle = @fopen($destination, 'xb');
        if (!is_resource($handle)) {
            self::fail('asset_download', 'Could not create download file.');
        }
        $headers = [];
        $contentLength = null;
        $bytes = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$contentLength): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'Content-Length:') === 0) {
                    $contentLength = (int) trim(substr($trimmed, 15));
                }
                if (stripos($trimmed, 'Location:') === 0) {
                    $headers['location'] = trim(substr($trimmed, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($handle, &$bytes, $maxBytes): int {
                $bytes += strlen($data);
                if ($bytes > $maxBytes) {
                    return 0;
                }
                $written = fwrite($handle, $data);
                return $written === false ? 0 : $written;
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($handle);
        if ($ok === false) {
            @unlink($destination);
            self::fail('asset_download', $error !== '' ? $error : 'cURL download failed.');
        }
        return ['status' => $status, 'headers' => $headers, 'location' => $headers['location'] ?? null, 'content_length' => $contentLength];
    }

    private function stream(string $url, string $destination, int $maxBytes): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TOTAL_TIMEOUT,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $input = @fopen($url, 'rb', false, $context);
        if (!is_resource($input)) {
            self::fail('asset_download', 'HTTPS stream could not be opened.');
        }
        $output = @fopen($destination, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            self::fail('asset_download', 'Could not create download file.');
        }
        $bytes = 0;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) {
                fclose($input);
                fclose($output);
                @unlink($destination);
                self::fail('asset_download', 'HTTPS stream read failed.');
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
                fclose($input);
                fclose($output);
                @unlink($destination);
                self::fail('asset_download', 'HTTPS stream exceeded the limit or could not be written.');
            }
        }
        fclose($input);
        fclose($output);
        $status = 0;
        $headers = [];
        $contentLength = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $line, $match)) {
                $status = (int) $match[1];
            }
            if (stripos($line, 'Location:') === 0) {
                $headers['location'] = trim(substr(trim($line), 9));
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr(trim($line), 15));
            }
        }
        return ['status' => $status, 'headers' => $headers, 'location' => $headers['location'] ?? null, 'content_length' => $contentLength];
    }

    private static function resolveUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            self::fail('asset_download', 'Redirect URL could not be resolved.');
        }
        if (strpos($location, '/') === 0) {
            return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $location;
        }
        $path = isset($parts['path']) ? dirname($parts['path']) : '/';
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($path, '/') . '/' . $location;
    }

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}

/* END tools/installer/InstallerDownloader.php */

/* BEGIN tools/installer/InstallerStaging.php */
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

/* END tools/installer/InstallerStaging.php */

/* BEGIN tools/installer/InstallerCore.php */
final class InstallerCore
{
    public const DEFAULT_POINTER_URL = 'https://tomoswords.org/download/install/latest.json';
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

/* END tools/installer/InstallerCore.php */

/* BEGIN tools/installer/InstallerVerifiedResult.php */
final class InstallerVerifiedResult
{
    public $stagingPath;
    public $installerRoot;
    public $version;
    public $manifest;
    public $candidateId;
    public $selectedMode;
    public $childName;

    public function __construct(string $stagingPath, string $installerRoot, string $version, array $manifest, string $candidateId, ?string $selectedMode = null, ?string $childName = null)
    {
        $this->stagingPath = $stagingPath;
        $this->installerRoot = $installerRoot;
        $this->version = $version;
        $this->manifest = $manifest;
        $this->candidateId = $candidateId;
        $this->selectedMode = $selectedMode;
        $this->childName = $childName;
        if (!is_dir($this->stagingPath) || is_link($this->stagingPath)
            || !is_dir($this->installerRoot) || is_link($this->installerRoot)
            || !preg_match('/\A[a-f0-9]{32}\z/', $this->candidateId)
            || ($this->manifest['version'] ?? null) !== $this->version
        ) {
            throw new InstallManifestException('placement_verify', 'Verified installer result is invalid.');
        }
        InstallManifest::validateManifest($this->manifest);
    }

    public static function fromArray(array $data): self
    {
        if (($data['verification_completed'] ?? false) !== true
            || !is_string($data['verified_staging_path'] ?? null)
            || !is_string($data['installer_root'] ?? null)
            || !is_string($data['version'] ?? null)
            || !is_array($data['manifest'] ?? null)
            || !is_string($data['transaction_candidate_id'] ?? null)
        ) {
            throw new InstallManifestException('placement_verify', 'Phase 2 verification result is not complete.');
        }
        return new self(
            $data['verified_staging_path'],
            $data['installer_root'],
            $data['version'],
            $data['manifest'],
            $data['transaction_candidate_id'],
            isset($data['selected_mode']) ? (string) $data['selected_mode'] : null,
            isset($data['child_directory']) ? (string) $data['child_directory'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'verified_staging_path' => $this->stagingPath,
            'installer_root' => $this->installerRoot,
            'version' => $this->version,
            'manifest' => $this->manifest,
            'transaction_candidate_id' => $this->candidateId,
            'selected_mode' => $this->selectedMode,
            'child_directory' => $this->childName,
            'verification_completed' => true,
        ];
    }
}

/* END tools/installer/InstallerVerifiedResult.php */

/* BEGIN tools/installer/InstallerJournal.php */
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

/* END tools/installer/InstallerJournal.php */

/* BEGIN tools/installer/InstallerPlacement.php */
final class InstallerPlacement
{
    public const MODE_CURRENT = 'current';
    public const MODE_CHILD = 'child';

    public function __construct(string $rootDir, string $installerVersion = 'phase3')
    {
        $real = realpath($rootDir);
        if ($real === false || !is_dir($real) || is_link($real)) {
            throw new InstallManifestException('root_fingerprint', 'Installer root is invalid.');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
        $this->installerVersion = $installerVersion;
        $this->management = $this->root . DIRECTORY_SEPARATOR . '.tomos-installer';
        $this->transactions = $this->management . DIRECTORY_SEPARATOR . 'transactions';
    }

    private $root;
    private $installerVersion;
    private $management;
    private $transactions;

    public function place(InstallerVerifiedResult $input, string $mode, ?string $childName = null, array $faults = []): array
    {
        $this->assertInput($input, $mode, $childName);
        $this->assertNotInstalled();
        $this->ensureManagement();
        $lock = $this->acquireLock();
        try {
            if ($mode === self::MODE_CURRENT) {
                return $this->placeCurrent($input, $faults);
            }
            return $this->placeChild($input, (string) $childName, $faults);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function recover(): array
    {
        $this->ensureManagement();
        $lock = $this->acquireLock();
        $results = [];
        try {
            if (!is_dir($this->transactions)) {
                return $results;
            }
            foreach (scandir($this->transactions) ?: [] as $id) {
                if ($id === '.' || $id === '..' || !preg_match('/\A[a-f0-9]{32}\z/', $id)) {
                    continue;
                }
                $journal = new InstallerJournal($this->root, $id);
                try {
                    $data = $journal->read();
                    $this->validateJournal($data, $id);
                    if (($data['mode'] ?? '') === self::MODE_CHILD) {
                        $results[] = $this->recoverChild($journal, $data);
                    } else {
                        $results[] = $this->recoverCurrent($journal, $data);
                    }
                } catch (Throwable $exception) {
                    $results[] = ['transaction_id' => $id, 'status' => 'recovery_required', 'error_code' => $exception instanceof InstallManifestException ? $exception->errorCode() : 'recovery_unsafe'];
                }
            }
        } finally {
            $this->releaseLock($lock);
        }
        return $results;
    }

    public function markerPath(): string
    {
        return $this->management . DIRECTORY_SEPARATOR . 'installed.json';
    }

    public function installedData(): array
    {
        $raw = @file_get_contents($this->markerPath());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            throw new InstallManifestException('recovery_unsafe', 'Installed marker is invalid.');
        }
        return $data;
    }

    private function placeCurrent(InstallerVerifiedResult $input, array $faults): array
    {
        InstallManifest::verifyStagingDirectory($input->stagingPath, $input->manifest);
        $files = $this->orderedFiles($input->manifest['files']);
        $directories = $this->directoryList($files);
        $this->assertNoCurrentCollisions($files, $directories);
        $id = $input->candidateId;
        $journal = $this->newJournal($input, self::MODE_CURRENT, '.', null, $files);
        $data = $this->journalData($input, self::MODE_CURRENT, '.', null, $files, $directories, 'placing');
        $journal->create($data);
        $createdMarker = false;
        try {
            foreach ($directories as $relative) {
                $entry = ['path' => $relative, 'state' => 'pending', 'sequence' => count($data['created_directories']) + 1];
                $data['created_directories'][] = $entry;
                $journal->write($data);
                $target = $this->target($relative);
                $this->assertAbsent($target);
                if (!@mkdir($target, 0755)) {
                    throw new InstallManifestException('placement_create', 'Could not create directory: ' . $relative);
                }
                $data['created_directories'][count($data['created_directories']) - 1]['state'] = 'created';
                $journal->write($data);
            }
            foreach ($files as $relative) {
                $record = $input->manifest['files'][$relative];
                $entry = ['path' => $relative, 'size' => $record['size'], 'sha256' => $record['sha256'], 'state' => 'pending', 'sequence' => count($data['created_files']) + 1];
                $data['created_files'][] = $entry;
                $journal->write($data);
                $this->copyExclusive($input->stagingPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $this->target($relative), $record);
                $index = count($data['created_files']) - 1;
                $data['created_files'][$index]['state'] = 'created';
                $journal->write($data);
                $data['created_files'][$index]['state'] = 'verified';
                $journal->write($data);
            }
            $data['state'] = 'verified';
            $journal->write($data);
            $this->writeMarker($input, $id, self::MODE_CURRENT, $faults);
            $createdMarker = true;
            $data['state'] = 'complete';
            $journal->write($data);
            $journal->removeIfSafe();
            $this->cleanupWorkFromResult($input);
            return ['mode' => self::MODE_CURRENT, 'version' => $input->version, 'transaction_id' => $id, 'marker' => $this->markerPath()];
        } catch (Throwable $exception) {
            if ($createdMarker) {
                $this->removeOwnedMarker($id);
            }
            $ok = $this->rollbackCurrent($journal, $data);
            if ($ok) {
                $journal->removeIfSafe();
                $this->cleanupWorkFromResult($input);
            } else {
                $data['state'] = 'rollback_failed';
                try { $journal->write($data); } catch (Throwable $ignored) { }
                throw new InstallManifestException('rollback_failed', 'Automatic rollback requires review.');
            }
            if ($exception instanceof InstallManifestException) {
                throw $exception;
            }
            throw new InstallManifestException('placement_create', 'Current-directory placement failed.');
        }
    }

    private function placeChild(InstallerVerifiedResult $input, string $childName, array $faults): array
    {
        InstallManifest::verifyStagingDirectory($input->stagingPath, $input->manifest);
        $target = $this->root . DIRECTORY_SEPARATOR . $childName;
        $this->assertChildName($childName);
        $this->assertAbsent($target);
        if (!is_writable($this->root)) {
            throw new InstallManifestException('target_exists', 'Child target parent is not writable.');
        }
        $id = $input->candidateId;
        $source = $this->root . DIRECTORY_SEPARATOR . '.tomos-installer-stage-' . $id;
        if (file_exists($source) || is_link($source)) {
            throw new InstallManifestException('rename_state', 'B staging sibling already exists.');
        }
        $journal = $this->newJournal($input, self::MODE_CHILD, $childName, $source, []);
        $data = $this->journalData($input, self::MODE_CHILD, $childName, $source, [], [], 'prepared');
        $journal->create($data);
        $createdMarker = false;
        try {
            if (!$this->sameFilesystem($input->stagingPath, $this->root)) {
                throw new InstallManifestException('rename_failed', 'B staging is not on the target filesystem.');
            }
            if (!@rename($input->stagingPath, $source)) {
                throw new InstallManifestException('rename_failed', 'B staging could not be moved beside target.');
            }
            $data['staging_relative'] = $this->relative($source);
            $data['state'] = 'ready_to_move';
            $journal->write($data);
            if (!@rename($source, $target)) {
                throw new InstallManifestException('rename_failed', 'B directory rename failed.');
            }
            $data['state'] = 'moved';
            $journal->write($data);
            InstallManifest::verifyStagingDirectory($target, $input->manifest);
            $data['state'] = 'verified';
            $journal->write($data);
            $this->writeMarker($input, $id, self::MODE_CHILD, $faults, $childName);
            $createdMarker = true;
            $data['state'] = 'complete';
            $journal->write($data);
            $journal->removeIfSafe();
            $this->cleanupWorkFromResult($input);
            return ['mode' => self::MODE_CHILD, 'directory' => $childName, 'version' => $input->version, 'transaction_id' => $id, 'marker' => $this->markerPath()];
        } catch (Throwable $exception) {
            if ($createdMarker) {
                $this->removeOwnedMarker($id);
            }
            $safe = $this->rollbackChild($journal, $data, $target, $source, $input->manifest);
            if ($safe) {
                $journal->removeIfSafe();
                $this->cleanupWorkFromResult($input);
            } else {
                $data['state'] = 'rollback_failed';
                try { $journal->write($data); } catch (Throwable $ignored) { }
                throw new InstallManifestException('rollback_failed', 'B rollback requires review.');
            }
            if ($exception instanceof InstallManifestException) {
                throw $exception;
            }
            throw new InstallManifestException('rename_failed', 'B directory placement failed.');
        }
    }

    private function rollbackCurrent(InstallerJournal $journal, array $data): bool
    {
        if (!$this->rootFingerprintMatches($data)) {
            return false;
        }
        $ok = true;
        foreach (array_reverse($data['created_files'] ?? []) as $entry) {
            $target = $this->target((string) ($entry['path'] ?? ''));
            if (!isSafeRollbackPath($entry['path'] ?? '') || !file_exists($target)) {
                continue;
            }
            if (is_link($target) || !is_file($target)) {
                $ok = false;
                continue;
            }
            if (!hash_matches_file($target, (string) ($entry['sha256'] ?? ''), (int) ($entry['size'] ?? -1)) || !@unlink($target)) {
                $ok = false;
            }
        }
        foreach (array_reverse($data['created_directories'] ?? []) as $entry) {
            $target = $this->target((string) ($entry['path'] ?? ''));
            if (!isSafeRollbackPath($entry['path'] ?? '') || !file_exists($target)) {
                continue;
            }
            if (is_link($target) || !is_dir($target) || !@rmdir($target)) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function rollbackChild(InstallerJournal $journal, array $data, string $target, string $source, array $manifest): bool
    {
        if (!$this->rootFingerprintMatches($data)) {
            return false;
        }
        if (is_dir($source) && !is_link($source)) {
            $this->removeTree($source);
            return !file_exists($source);
        }
        if (is_dir($target) && !is_link($target)) {
            try {
                InstallManifest::verifyStagingDirectory($target, $manifest);
            } catch (Throwable $exception) {
                return false;
            }
            $this->removeTree($target);
            return !file_exists($target);
        }
        return !file_exists($source) && !file_exists($target);
    }

    private function recoverCurrent(InstallerJournal $journal, array $data): array
    {
        if (!$this->rootFingerprintMatches($data)) {
            throw new InstallManifestException('root_fingerprint', 'Transaction root does not match.');
        }
        if (!$this->rollbackCurrent($journal, $data)) {
            throw new InstallManifestException('recovery_unsafe', 'Current transaction cannot be safely cleaned.');
        }
        $journal->removeIfSafe();
        $this->cleanupRelativeWork($data['work_relative'] ?? '');
        return ['transaction_id' => $data['transaction_id'], 'status' => 'cleaned'];
    }

    private function recoverChild(InstallerJournal $journal, array $data): array
    {
        if (!$this->rootFingerprintMatches($data)) {
            throw new InstallManifestException('root_fingerprint', 'Transaction root does not match.');
        }
        $target = $this->target((string) ($data['target_child'] ?? ''));
        $source = $this->resolveRelative((string) ($data['staging_relative'] ?? ''));
        $state = (string) ($data['state'] ?? '');
        if (in_array($state, ['moved', 'verified'], true) || ($state === 'ready_to_move' && is_dir($target))) {
            InstallManifest::verifyStagingDirectory($target, $data['manifest']);
            $this->writeMarkerData($data['tomos_version'], $data['transaction_id'], self::MODE_CHILD, (string) $data['target_child']);
            $data['state'] = 'complete';
            $journal->write($data);
            $journal->removeIfSafe();
            $this->cleanupRelativeWork($data['work_relative'] ?? '');
            return ['transaction_id' => $data['transaction_id'], 'status' => 'forward_recovered'];
        }
        if (is_dir($target) && !is_link($target)) {
            throw new InstallManifestException('recovery_unsafe', 'B target exists in an unverified state.');
        }
        if (is_dir($source) && !is_link($source)) {
            $this->removeTree($source);
        }
        if (file_exists($source) || file_exists($target)) {
            throw new InstallManifestException('recovery_unsafe', 'B transaction left an unexpected path.');
        }
        $journal->removeIfSafe();
        $this->cleanupRelativeWork($data['work_relative'] ?? '');
        return ['transaction_id' => $data['transaction_id'], 'status' => 'cleaned'];
    }

    private function assertInput(InstallerVerifiedResult $input, string $mode, ?string $child): void
    {
        $root = realpath($input->installerRoot);
        if ($root === false || $root !== $this->root || !is_dir($input->stagingPath) || is_link($input->stagingPath)) {
            throw new InstallManifestException('placement_verify', 'Verified result root does not match placement root.');
        }
        if ($mode !== self::MODE_CURRENT && $mode !== self::MODE_CHILD) {
            throw new InstallManifestException('placement_verify', 'Placement mode is invalid.');
        }
        if ($mode === self::MODE_CHILD) {
            $this->assertChildName((string) $child);
        }
    }

    private function assertNotInstalled(): void
    {
        if (is_file($this->markerPath()) || is_link($this->markerPath())) {
            throw new InstallManifestException('target_exists', 'Tomos is already installed.');
        }
    }

    private function assertNoCurrentCollisions(array $files, array $directories): void
    {
        foreach (array_merge($directories, $files) as $relative) {
            $target = $this->target($relative);
            $this->assertParentChainSafe($target);
            if (file_exists($target) || is_link($target)) {
                throw new InstallManifestException('target_collision', 'Installation target already exists: ' . $relative);
            }
        }
    }

    private function assertParentChainSafe(string $target): void
    {
        $cursor = dirname($target);
        while ($cursor !== $this->root && $cursor !== dirname($cursor)) {
            if (is_link($cursor) || (file_exists($cursor) && !is_dir($cursor))) {
                throw new InstallManifestException('target_symlink', 'Installation parent is unsafe.');
            }
            $cursor = dirname($cursor);
        }
        if ($cursor !== $this->root) {
            throw new InstallManifestException('target_symlink', 'Installation path escaped root.');
        }
    }

    private function assertAbsent(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new InstallManifestException('target_exists', 'Target already exists.');
        }
    }

    private function assertChildName(string $name): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $name) !== 1) {
            throw new InstallManifestException('target_exists', 'Child directory name is invalid.');
        }
    }

    private function orderedFiles(array $files): array
    {
        $paths = array_keys($files);
        usort($paths, static function (string $a, string $b): int {
            $rank = static function (string $path): int {
                if ($path === 'index.php') return 1000;
                if ($path === '.htaccess') return 900;
                if (strpos($path, 'core/') === 0) return 100;
                if (strpos($path, 'setup/') === 0) return 110;
                if (strpos($path, 'themes/') === 0) return 120;
                return 200;
            };
            return ($rank($a) <=> $rank($b)) ?: strcmp($a, $b);
        });
        return $paths;
    }

    private function directoryList(array $files): array
    {
        $dirs = [];
        foreach ($files as $file) {
            $parts = explode('/', $file);
            array_pop($parts);
            $current = '';
            foreach ($parts as $part) {
                $current = $current === '' ? $part : $current . '/' . $part;
                $dirs[$current] = true;
            }
        }
        $dirs = array_keys($dirs);
        usort($dirs, static function (string $a, string $b): int {
            return (substr_count($a, '/') <=> substr_count($b, '/')) ?: strcmp($a, $b);
        });
        return $dirs;
    }

    private function copyExclusive(string $source, string $target, array $record): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new InstallManifestException('placement_verify', 'Staging file is unavailable.');
        }
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new InstallManifestException('placement_create', 'Could not exclusively create target file.');
        }
        $context = hash_init('sha256');
        $bytes = 0;
        $ok = true;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) { $ok = false; break; }
            $length = strlen($chunk);
            $bytes += $length;
            if ($length > 0) {
                hash_update($context, $chunk);
                if (fwrite($output, $chunk) !== $length) { $ok = false; break; }
            }
        }
        if ($ok) $ok = fflush($output);
        fclose($input);
        fclose($output);
        $hash = hash_final($context);
        if (!$ok || $bytes !== (int) $record['size'] || !hash_equals(strtolower((string) $record['sha256']), strtolower($hash))) {
            throw new InstallManifestException('placement_verify', 'Placed file verification failed.');
        }
        @chmod($target, 0644);
    }

    private function writeMarker(InstallerVerifiedResult $input, string $id, string $mode, array $faults, ?string $child = null): void
    {
        $this->writeMarkerData($input->version, $id, $mode, $child);
    }

    private function writeMarkerData(string $version, string $id, string $mode, ?string $child = null): void
    {
        $data = ['schema_version' => 1, 'tomos_version' => $version, 'transaction_id' => $id, 'mode' => $mode, 'completed_at' => gmdate(DATE_ATOM)];
        if ($child !== null) $data['target_child'] = $child;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = $this->markerPath() . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle) || fwrite($handle, $json) === false || !fflush($handle)) {
            if (is_resource($handle)) fclose($handle);
            @unlink($temporary);
            throw new InstallManifestException('installed_marker', 'Could not write installed marker.');
        }
        fclose($handle);
        if (!@rename($temporary, $this->markerPath())) {
            @unlink($temporary);
            throw new InstallManifestException('installed_marker', 'Could not atomically install marker.');
        }
        @chmod($this->markerPath(), 0600);
    }

    private function removeOwnedMarker(string $id): void
    {
        $data = json_decode((string) @file_get_contents($this->markerPath()), true);
        if (is_array($data) && ($data['transaction_id'] ?? '') === $id) @unlink($this->markerPath());
    }

    private function newJournal(InstallerVerifiedResult $input, string $mode, string $target, ?string $staging, array $files): InstallerJournal
    {
        return new InstallerJournal($this->root, $input->candidateId);
    }

    private function journalData(InstallerVerifiedResult $input, string $mode, string $target, ?string $staging, array $files, array $directories, string $state): array
    {
        return [
            'schema_version' => InstallerJournal::SCHEMA_VERSION,
            'transaction_id' => $input->candidateId,
            'installer_version' => $this->installerVersion,
            'mode' => $mode,
            'state' => $state,
            'tomos_version' => $input->version,
            'root_fingerprint' => InstallerJournal::rootFingerprint($this->root),
            'manifest' => $input->manifest,
            'created_files' => [],
            'created_directories' => [],
            'target' => $target,
            'target_child' => $mode === self::MODE_CHILD ? $target : null,
            'staging_relative' => $staging !== null ? $this->relative($staging) : null,
            'work_relative' => $this->relative(dirname($input->stagingPath)),
            'started_at' => gmdate(DATE_ATOM),
        ];
    }

    private function validateJournal(array $data, string $id): void
    {
        if (($data['schema_version'] ?? null) !== InstallerJournal::SCHEMA_VERSION
            || ($data['transaction_id'] ?? null) !== $id
            || ($data['root_fingerprint'] ?? null) !== InstallerJournal::rootFingerprint($this->root)
            || !in_array(($data['mode'] ?? null), [self::MODE_CURRENT, self::MODE_CHILD], true)
            || !is_array($data['manifest'] ?? null)
        ) {
            throw new InstallManifestException('recovery_unsafe', 'Transaction journal schema or root is unsafe.');
        }
        InstallManifest::validateManifest($data['manifest']);
    }

    private function rootFingerprintMatches(array $data): bool
    {
        return isset($data['root_fingerprint']) && hash_equals((string) $data['root_fingerprint'], InstallerJournal::rootFingerprint($this->root));
    }

    private function ensureManagement(): void
    {
        if (is_link($this->management) || (file_exists($this->management) && !is_dir($this->management))) {
            throw new InstallManifestException('transaction_create', 'Installer management directory is unsafe.');
        }
        if (!is_dir($this->management) && !@mkdir($this->management, 0700, true)) throw new InstallManifestException('transaction_create', 'Could not create management directory.');
        if (!is_dir($this->transactions) && !@mkdir($this->transactions, 0700, true)) throw new InstallManifestException('transaction_create', 'Could not create transaction directory.');
        $deny = $this->management . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($deny) && !is_link($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n", LOCK_EX);
    }

    private function acquireLock()
    {
        $path = $this->management . DIRECTORY_SEPARATOR . 'install.lock';
        if (is_link($path)) throw new InstallManifestException('lock', 'Installer lock is unsafe.');
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new InstallManifestException('lock', 'Another placement is active.');
        }
        @chmod($path, 0600);
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
    }

    private function target(string $relative): string
    {
        if (!InstallManifest::isSafeRelativePath($relative)) throw new InstallManifestException('target_symlink', 'Unsafe target path.');
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function relative(string $path): string
    {
        $canonical = realpath($path);
        $real = str_replace(DIRECTORY_SEPARATOR, '/', $canonical !== false ? $canonical : $path);
        $root = str_replace(DIRECTORY_SEPARATOR, '/', $this->root) . '/';
        if (strpos($real, $root) !== 0) throw new InstallManifestException('root_fingerprint', 'Path is outside installer root.');
        return substr($real, strlen($root));
    }

    private function resolveRelative(string $relative): string
    {
        return $this->target($relative);
    }

    private function sameFilesystem(string $a, string $b): bool
    {
        $a = realpath($a); $b = realpath($b);
        if ($a === false || $b === false) return false;
        $sa = @stat($a); $sb = @stat($b);
        return is_array($sa) && is_array($sb) && (int) ($sa['dev'] ?? -1) === (int) ($sb['dev'] ?? -2);
    }

    private function cleanupWorkFromResult(InstallerVerifiedResult $input): void
    {
        $work = $this->management . DIRECTORY_SEPARATOR . 'work-' . $input->candidateId;
        $management = realpath($this->management);
        $realWork = realpath($work);
        if ($management !== false && $realWork !== false
            && strpos($realWork, rtrim($management, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0) {
            $this->removeTree($realWork);
        }
    }

    private function cleanupRelativeWork(string $relative): void
    {
        if ($relative !== '') $this->removeTree($this->resolveRelative($relative));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $item) { if ($item !== '.' && $item !== '..') $this->removeTree($path . DIRECTORY_SEPARATOR . $item); }
        @rmdir($path);
    }

}

function isSafeRollbackPath($path): bool
{
    return is_string($path) && InstallManifest::isSafeRelativePath($path) && strpos($path, '.tomos-installer') !== 0;
}

function hash_matches_file(string $path, string $expectedHash, int $expectedSize): bool
{
    return is_file($path) && filesize($path) === $expectedSize && is_string($hash = hash_file('sha256', $path)) && hash_equals(strtolower($expectedHash), strtolower($hash));
}

/* END tools/installer/InstallerPlacement.php */

/* BEGIN tools/installer/InstallerLifecycle.php */
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

/* END tools/installer/InstallerLifecycle.php */

/* BEGIN tools/installer/InstallerApplication.php */
final class InstallerApplication
{
    public const VERSION = 'phase4.0.0';
    public const FALLBACK_URL = 'https://tomoswords.org/start/install/';

    public function __construct(string $rootDir, array $config = [], ?InstallerCore $core = null, ?InstallerPlacement $placement = null, ?InstallerLifecycle $lifecycle = null)
    {
        $this->root = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->config = $config;
        $this->core = $core ?? new InstallerCore($this->root, $config);
        $this->placement = $placement ?? new InstallerPlacement($this->root, self::VERSION);
        $this->lifecycle = $lifecycle ?? new InstallerLifecycle($this->root, self::VERSION);
    }

    private $root;
    private $config;
    private $core;
    private $placement;
    private $lifecycle;

    public function run(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $bootstrap = null;
        $recovery = [];
        $diagnostics = ['errors' => [], 'warnings' => []];
        $message = null;
        $result = null;
        try {
            $this->core->bootstrap();
            $recovery = $this->placement->recover();
            foreach ($recovery as $recovered) {
                if (($recovered['status'] ?? '') === 'forward_recovered') {
                    $installed = $this->placement->installedData();
                    $this->lifecycle->disable([
                        'version' => $installed['tomos_version'] ?? '',
                        'transaction_id' => $installed['transaction_id'] ?? '',
                    ]);
                }
            }
            if ($method === 'GET') {
                $diagnostics = $this->core->diagnostics();
            } elseif ($method === 'POST') {
                $result = $this->installPost();
            } else {
                http_response_code(405);
                $message = ['kind' => 'error', 'text' => 'この操作は利用できません。'];
            }
        } catch (Throwable $exception) {
            $message = $this->messageFor($exception);
            if ($message['kind'] === 'recovery') http_response_code(409);
        }
        if ($result !== null) {
            $this->renderComplete($result);
            return;
        }
        if ($this->lifecycle->isDisabled()) {
            http_response_code(410);
            $this->renderDisabled();
            return;
        }
        $this->render($diagnostics, $recovery, $message);
    }

    public function installPost(?array $post = null): array
    {
        $post = $post ?? $_POST;
        $token = is_string($post['csrf'] ?? null) ? $post['csrf'] : '';
        $mode = (string) ($post['mode'] ?? InstallerPlacement::MODE_CURRENT);
        $child = $mode === InstallerPlacement::MODE_CHILD ? (string) ($post['child_directory'] ?? '') : null;
        if ($mode !== InstallerPlacement::MODE_CURRENT && $mode !== InstallerPlacement::MODE_CHILD) {
            throw new InstallManifestException('target_exists', 'Placement mode is invalid.');
        }
        if ($mode === InstallerPlacement::MODE_CHILD && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', (string) $child) !== 1) {
            throw new InstallManifestException('target_exists', 'Child directory name is invalid.');
        }
        $prepared = $this->core->prepare($token);
        $verified = InstallerVerifiedResult::fromArray($prepared);
        $verified->selectedMode = $mode;
        $verified->childName = $child;
        $installed = $this->placement->place($verified, $mode, $child);
        $this->lifecycle->disable($installed);
        $selfDeleted = $this->lifecycle->trySelfDelete((string) ($this->config['installer_path'] ?? ''), (bool) ($this->config['allow_self_delete'] ?? false));
        $installed['self_deleted'] = $selfDeleted;
        $installed['start_url'] = $this->lifecycle->completionTarget($mode, $child);
        return $installed;
    }

    public function renderDiagnostics(): array
    {
        return $this->core->diagnostics();
    }

    private function render(array $diagnostics, array $recovery, ?array $message): void
    {
        $token = '';
        try { $token = htmlspecialchars($this->core->csrfToken(), ENT_QUOTES, 'UTF-8'); } catch (Throwable $ignored) { }
        $hasErrors = $diagnostics['errors'] !== [];
        $recoveryUnsafe = false;
        $recovered = false;
        foreach ($recovery as $item) {
            if (($item['status'] ?? '') === 'recovery_required') $recoveryUnsafe = true;
            if (($item['status'] ?? '') === 'cleaned') $recovered = true;
            if (($item['status'] ?? '') === 'forward_recovered') $message = ['kind' => 'success', 'text' => '前回のインストール処理を確認しました。Tomosのインストールは完了しています。'];
        }
        if ($recoveryUnsafe) $message = ['kind' => 'recovery', 'text' => '前回のインストール状態を自動で復旧できませんでした。安全のため、自動処理を停止しています。'];
        if ($recovered && $message === null) $message = ['kind' => 'notice', 'text' => '前回のインストールは完了しませんでした。安全に初期化しました。もう一度インストールできます。'];
        $fallback = htmlspecialchars((string) ($this->config['fallback_url'] ?? self::FALLBACK_URL), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tomos かんたんインストール</title><style>' . self::css() . '</style></head><body><main class="card"><h1>Tomos かんたんインストール</h1>';
        if ($message !== null) echo '<div class="message ' . htmlspecialchars((string) $message['kind'], ENT_QUOTES, 'UTF-8') . '" role="alert">' . htmlspecialchars((string) $message['text'], ENT_QUOTES, 'UTF-8') . '</div>';
        $blocked = $hasErrors || ($message !== null && in_array((string) ($message['kind'] ?? ''), ['error', 'recovery'], true));
        if ($recoveryUnsafe) {
            echo '<h2>自動復旧を停止しました</h2><p>前回のインストール状態を自動で復旧できませんでした。</p><p>安全のため、この場所へ新しいファイルをアップロードしないでください。</p><p class="diagnostic">診断コード: ' . $this->diagnosticCode('recovery_unsafe') . '</p>';
        } elseif ($blocked) {
            echo '<h2>かんたんインストールを利用できません</h2><p>このサーバーでは、かんたんインストールを利用できません。</p><p>Tomosは通常のファイルアップロードで設置できます。</p><p><a class="button secondary" href="' . $fallback . '">設置方法を見る</a></p>';
        } else {
            echo '<p>Tomosをこのサーバーに設置します。</p><form method="post" id="installer-form"><input type="hidden" name="csrf" value="' . $token . '"><fieldset><legend>設置場所</legend><label><input type="radio" name="mode" value="current" checked> この場所に設置</label><label><input type="radio" name="mode" value="child"> 新しいフォルダに設置</label><div id="child-wrap" hidden><label for="child_directory">フォルダ名</label><input id="child_directory" name="child_directory" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" autocomplete="off"><small>半角英数字、ハイフン、アンダーバーが使えます。</small></div></fieldset><button type="submit" id="submit">Tomosをインストール</button></form><p class="status" id="status" hidden>Tomosを準備しています。この画面を閉じずにお待ちください。</p>';
        }
        $diagnostic = $recoveryUnsafe ? 'recovery_unsafe' : ($message['code'] ?? ($hasErrors ? 'environment' : 'ready'));
        echo '<p class="diagnostic">診断コード: ' . $this->diagnosticCode((string) $diagnostic) . '</p></main><script>document.querySelectorAll("input[name=mode]").forEach(function (e) { e.addEventListener("change", function () { document.getElementById("child-wrap").hidden = this.value !== "child"; }); }); document.getElementById("installer-form")?.addEventListener("submit", function () { document.getElementById("submit").disabled = true; document.getElementById("status").hidden = false; });</script></body></html>';
    }

    private function renderComplete(array $result): void
    {
        $url = htmlspecialchars((string) $result['start_url'], ENT_QUOTES, 'UTF-8');
        $delete = empty($result['self_deleted']) ? '<p class="notice">安全のため、可能であれば install.php を削除してください。</p>' : '';
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tomosのインストール完了</title><style>' . self::css() . '</style></head><body><main class="card"><h1>Tomosのインストールが完了しました。</h1><p>Tomosを利用できます。</p><p><a class="button" href="' . $url . '">Tomosをはじめる</a></p>' . $delete . '</main></body></html>';
    }

    private function renderDisabled(): void
    {
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>使用済みインストーラー</title><style>' . self::css() . '</style></head><body><main class="card"><h1>このインストーラーはすでに使用済みです。</h1><p>Tomosはインストール済みです。</p><p>install.php は削除して構いません。</p></main></body></html>';
    }

    private function messageFor(Throwable $exception): array
    {
        $code = $exception instanceof InstallManifestException ? $exception->errorCode() : 'internal';
        $kind = in_array($code, ['recovery_unsafe', 'rollback_failed'], true) ? 'recovery' : 'error';
        if (in_array($code, ['environment', 'staging_create', 'lock', 'session', 'csrf', 'bootstrap_owner', 'target_exists', 'target_collision', 'target_symlink', 'rename_failed', 'rename_state'], true)) $text = 'このサーバーでは、かんたんインストールを利用できません。';
        elseif (in_array($code, ['manifest_signature', 'manifest_schema', 'manifest_version', 'asset_hash', 'asset_size', 'zip_open', 'zip_path', 'zip_duplicate', 'zip_symlink', 'zip_limits', 'zip_contents', 'file_hash', 'file_size', 'required_file', 'placement_verify'], true)) $text = 'Tomosの配布データを安全に確認できませんでした。';
        elseif (in_array($code, ['pointer_download', 'pointer_schema', 'manifest_download', 'signature_download', 'asset_download'], true)) $text = 'Tomosを取得できませんでした。';
        elseif (in_array($code, ['installed_marker', 'disable_marker', 'rollback_failed', 'recovery_unsafe'], true)) $text = 'インストール状態を自動で復旧できませんでした。';
        else $text = 'インストールを完了できませんでした。';
        return ['kind' => $kind, 'text' => $text, 'code' => $code];
    }

    private function diagnosticCode(string $code): string
    {
        return 'TOMOS-INSTALL-' . strtoupper(substr(hash('sha256', $code . '|' . gmdate('Y-m-d-H')), 0, 10));
    }

    private static function css(): string
    {
        return 'body{margin:0;background:#f5f3ef;color:#262522;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6}.card{box-sizing:border-box;width:min(100% - 32px,640px);margin:8vh auto;padding:32px;background:#fff;border:1px solid #ddd8cf;border-radius:14px;box-shadow:0 8px 30px #0000000d}h1{font-size:1.6rem;line-height:1.3;margin-top:0}h2{font-size:1.2rem}fieldset{border:0;padding:0;margin:24px 0}legend{font-weight:700;margin-bottom:10px}label{display:block;margin:10px 0}input[type=text],input:not([type]){box-sizing:border-box;max-width:100%;padding:9px;border:1px solid #aaa;border-radius:6px}#child_directory{display:block;margin-top:6px;width:100%}small{display:block;color:#666}.button,button{display:inline-block;border:0;border-radius:7px;padding:10px 18px;background:#28634d;color:#fff;text-decoration:none;font:inherit;cursor:pointer}.button.secondary{background:#555}.button:focus,button:focus,input:focus{outline:3px solid #9dd2bd;outline-offset:2px}button:disabled{opacity:.6;cursor:wait}.message{padding:12px;border-radius:7px;margin:14px 0}.message.notice{background:#fff7db}.message.error,.message.recovery{background:#fde8e8}.message.success{background:#e3f4e9}.notice{padding:12px;background:#fff7db;border-radius:7px}.diagnostic{color:#666;font-size:.78rem;word-break:break-all}.status{margin-top:18px;color:#28634d}@media(max-width:480px){.card{margin:3vh auto;padding:22px}}';
    }
}

/* END tools/installer/InstallerApplication.php */

InstallManifest::setRequiredFileLists(array (
  'distribution' =>
  array (
    0 => 'index.php',
    1 => '.htaccess',
    2 => 'VERSION',
    3 => 'LICENSE',
    4 => 'NOTICE',
    5 => 'DISCLAIMER.md',
    6 => 'TRADEMARKS.md',
    7 => 'CHANGELOG.md',
    8 => 'SECURITY.md',
    9 => 'KNOWN_LIMITATIONS.md',
    10 => 'cache/.gitkeep',
    11 => 'cache/index/.gitkeep',
    12 => 'cache/html/.gitkeep',
    13 => 'cache/logs/.gitkeep',
    14 => 'cache/security/post-rate-limit/.gitkeep',
    15 => 'cache/security/post-auth/.gitkeep',
    16 => 'cache/security/post-submissions/.gitkeep',
    17 => 'cache/post-upload-sessions/.gitkeep',
    18 => 'post/index.php',
    19 => 'post/inbox/api/index.php',
    20 => 'post/update-finalize/index.php',
    21 => 'update/index.php',
    22 => 'update/public-key.pem',
    23 => 'storage/.gitkeep',
    24 => 'storage/update-backups/.gitkeep',
    25 => 'storage/update-logs/.gitkeep',
    26 => 'storage/update-tmp/.gitkeep',
    27 => 'core/UpdateLock.php',
    28 => 'core/UpdateService.php',
    29 => 'core/InstalledIntegrityVerifier.php',
    30 => 'core/UpdaterSelfUpdate.php',
    31 => 'core/required-installed-files.txt',
    32 => 'core/PostBasicPage.php',
    33 => 'core/PostAuthRememberToken.php',
    34 => 'core/PostSubmissionGuard.php',
    35 => 'core/PostRateLimiter.php',
    36 => 'core/PostUpload.php',
    37 => 'core/PostInbox.php',
    38 => 'core/PostInboxPreview.php',
    39 => 'core/PostDrafts.php',
    40 => 'core/PostPublished.php',
    41 => 'core/PostInboxApi.php',
    42 => 'core/PostInboxAutoPublisher.php',
    43 => 'core/PostImageUploadSessionStore.php',
    44 => 'core/SiteSettingsConfigWriter.php',
    45 => 'core/ThemePackageException.php',
    46 => 'core/ThemePackageInstaller.php',
    47 => 'core/ThemePackagePolicy.php',
    48 => 'core/PasskeyEnvironment.php',
    49 => 'core/PasskeyCredentialStore.php',
    50 => 'core/PasskeyChallengeStore.php',
    51 => 'core/PasskeyWebAuthnClient.php',
    52 => 'core/LbuchsPasskeyWebAuthnClient.php',
    53 => 'core/PasskeyRegistrationService.php',
    54 => 'core/PasskeyAuthenticationService.php',
    55 => 'core/PasskeyManagementService.php',
    56 => 'core/PostPasswordHashUpdater.php',
    57 => 'core/PasskeyPasswordResetService.php',
    58 => 'core/PasskeyServerRecoveryService.php',
    59 => 'core/webauthn/composer.json',
    60 => 'core/webauthn/composer.lock',
    61 => 'core/webauthn/vendor/autoload.php',
    62 => 'core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php',
    63 => 'post/settings/index.php',
    64 => 'post/theme/index.php',
    65 => 'post/theme/confirm/index.php',
    66 => 'post/theme/add/index.php',
    67 => 'post/theme/add/confirm/index.php',
    68 => 'post/reset/index.php',
    69 => 'post/assets/tomos-post-security.css',
    70 => 'post/security/index.php',
    71 => 'post/passkey/login/index.php',
    72 => 'post/passkey/manage/index.php',
    73 => 'post/passkey/register/index.php',
    74 => 'post/passkey/password-reset/index.php',
    75 => 'post/passkey/recovery/index.php',
    76 => 'trash/.gitkeep',
  ),
  'installed' =>
  array (
    0 => 'index.php',
    1 => '.htaccess',
    2 => 'VERSION',
    3 => 'post/index.php',
    4 => 'post/inbox/api/index.php',
    5 => 'post/update-finalize/index.php',
    6 => 'update/index.php',
    7 => 'update/public-key.pem',
    8 => 'core/UpdateLock.php',
    9 => 'core/UpdateService.php',
    10 => 'core/InstalledIntegrityVerifier.php',
    11 => 'core/UpdaterSelfUpdate.php',
    12 => 'core/required-installed-files.txt',
    13 => 'core/SetupUrlResolver.php',
    14 => 'core/PostBasicPage.php',
    15 => 'core/PostAuthRememberToken.php',
    16 => 'core/PostSubmissionGuard.php',
    17 => 'core/PostRateLimiter.php',
    18 => 'core/PostUpload.php',
    19 => 'core/PostUploadInput.php',
    20 => 'core/PostSubmissionPreparer.php',
    21 => 'core/PostConflictManager.php',
    22 => 'core/PostInbox.php',
    23 => 'core/PostInboxPreview.php',
    24 => 'core/PostDrafts.php',
    25 => 'core/PostPublished.php',
    26 => 'core/PostInboxApi.php',
    27 => 'core/PostInboxAutoPublisher.php',
    28 => 'core/PostPublisher.php',
    29 => 'core/PostImageUploadSessionStore.php',
    30 => 'core/SiteSettingsConfigWriter.php',
    31 => 'core/ThemePackageException.php',
    32 => 'core/ThemePackageInstaller.php',
    33 => 'core/ThemePackagePolicy.php',
    34 => 'core/PasskeyEnvironment.php',
    35 => 'core/PasskeyCredentialStore.php',
    36 => 'core/PasskeyChallengeStore.php',
    37 => 'core/PasskeyWebAuthnClient.php',
    38 => 'core/LbuchsPasskeyWebAuthnClient.php',
    39 => 'core/PasskeyRegistrationService.php',
    40 => 'core/PasskeyAuthenticationService.php',
    41 => 'core/PasskeyManagementService.php',
    42 => 'core/PostPasswordHashUpdater.php',
    43 => 'core/PageSorter.php',
    44 => 'core/PublishedMetadata.php',
    45 => 'core/PasskeyPasswordResetService.php',
    46 => 'core/PasskeyServerRecoveryService.php',
    47 => 'core/webauthn/composer.json',
    48 => 'core/webauthn/composer.lock',
    49 => 'core/webauthn/vendor/autoload.php',
    50 => 'core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php',
    51 => 'post/settings/index.php',
    52 => 'post/theme/index.php',
    53 => 'post/theme/confirm/index.php',
    54 => 'post/theme/add/index.php',
    55 => 'post/theme/add/confirm/index.php',
    56 => 'post/reset/index.php',
    57 => 'post/assets/tomos-post-security.css',
    58 => 'post/security/index.php',
    59 => 'post/passkey/login/index.php',
    60 => 'post/passkey/manage/index.php',
    61 => 'post/passkey/register/index.php',
    62 => 'post/passkey/password-reset/index.php',
    63 => 'post/passkey/recovery/index.php',
  ),
));

/* BEGIN installer entry */
(new InstallerApplication(__DIR__, [
    'installer_path' => __FILE__,
    'allow_self_delete' => true,
]))->run();
/* END installer entry */
