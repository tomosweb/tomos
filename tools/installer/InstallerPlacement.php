<?php

declare(strict_types=1);

final class InstallerSimulatedTermination extends RuntimeException
{
}

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
                $this->fault($faults, 'directory_pending');
                $target = $this->target($relative);
                $this->assertAbsent($target);
                if (!@mkdir($target, 0755)) {
                    throw new InstallManifestException('placement_create', 'Could not create directory: ' . $relative);
                }
                $data['created_directories'][count($data['created_directories']) - 1]['state'] = 'created';
                $journal->write($data);
                $this->fault($faults, 'directory_created');
            }
            foreach ($files as $relative) {
                $record = $input->manifest['files'][$relative];
                $entry = ['path' => $relative, 'size' => $record['size'], 'sha256' => $record['sha256'], 'state' => 'pending', 'sequence' => count($data['created_files']) + 1];
                $data['created_files'][] = $entry;
                $journal->write($data);
                $this->fault($faults, 'file_pending', $relative);
                $this->copyExclusive($input->stagingPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $this->target($relative), $record);
                $index = count($data['created_files']) - 1;
                $data['created_files'][$index]['state'] = 'created';
                $journal->write($data);
                $this->fault($faults, 'file_created', $relative);
                $data['created_files'][$index]['state'] = 'verified';
                $journal->write($data);
                $this->fault($faults, 'file_verified', $relative);
            }
            $data['state'] = 'verified';
            $journal->write($data);
            $this->fault($faults, 'before_marker');
            $this->writeMarker($input, $id, self::MODE_CURRENT, $faults);
            $createdMarker = true;
            $data['state'] = 'complete';
            $journal->write($data);
            $journal->removeIfSafe();
            $this->cleanupWorkFromResult($input);
            return ['mode' => self::MODE_CURRENT, 'version' => $input->version, 'transaction_id' => $id, 'marker' => $this->markerPath()];
        } catch (InstallerSimulatedTermination $exception) {
            throw $exception;
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
            $this->fault($faults, 'ready_to_move');
            if ($this->faultEnabled($faults, 'rename_fail')) {
                throw new InstallManifestException('rename_failed', 'Injected B rename failure.');
            }
            if (!@rename($source, $target)) {
                throw new InstallManifestException('rename_failed', 'B directory rename failed.');
            }
            $data['state'] = 'moved';
            $journal->write($data);
            $this->fault($faults, 'after_rename');
            InstallManifest::verifyStagingDirectory($target, $input->manifest);
            $data['state'] = 'verified';
            $journal->write($data);
            $this->fault($faults, 'before_marker');
            $this->writeMarker($input, $id, self::MODE_CHILD, $faults, $childName);
            $createdMarker = true;
            $data['state'] = 'complete';
            $journal->write($data);
            $journal->removeIfSafe();
            $this->cleanupWorkFromResult($input);
            return ['mode' => self::MODE_CHILD, 'directory' => $childName, 'version' => $input->version, 'transaction_id' => $id, 'marker' => $this->markerPath()];
        } catch (InstallerSimulatedTermination $exception) {
            throw $exception;
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
        if ($this->faultEnabled($faults, 'marker_fail')) {
            throw new InstallManifestException('installed_marker', 'Injected installed marker failure.');
        }
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

    private function fault(array $faults, string $point, ?string $path = null): void
    {
        if ($this->faultEnabled($faults, $point, $path)) throw new InstallerSimulatedTermination('Injected termination at ' . $point);
    }

    private function faultEnabled(array $faults, string $point, ?string $path = null): bool
    {
        return !empty($faults[$point]) && ($faults[$point] === true || $faults[$point] === $path);
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
