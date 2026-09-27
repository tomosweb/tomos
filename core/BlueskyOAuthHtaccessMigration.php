<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthHtaccessMigration
{
    private const BACKUP_ROOT = 'storage/update-backups';
    private const BACKUP_PREFIX = 'bluesky-oauth-htaccess-';

    /**
     * Build a read-only migration plan. This method never writes to the install.
     *
     * @return array<string,mixed>
     */
    public static function inspectMigration(string $rootDir): array
    {
        return self::sanitize(self::buildPlan($rootDir));
    }

    /**
     * Apply a previously validated migration only when explicitly called.
     *
     * @return array<string,mixed>
     */
    public static function migrate(string $rootDir): array
    {
        $plan = self::buildPlan($rootDir);
        $result = self::sanitize($plan);
        $result['can_migrate'] = false;
        $result['backup_created'] = false;
        $result['backup_id'] = '';
        $result['rolled_back'] = false;

        if (($plan['status'] ?? '') === 'managed' && !empty($plan['diagnostic']['static_backing_enabled'])) {
            $result['status'] = 'not_needed';
            $result['current_status'] = 'managed';
            $result['can_migrate'] = false;
            return $result;
        }
        if (empty($plan['can_migrate'])) {
            $result['status'] = 'blocked';
            return $result;
        }

        $path = (string) ($plan['_path'] ?? '');
        $original = $plan['_contents'] ?? null;
        $analysis = $plan['_analysis'] ?? null;
        if ($path === '' || !is_string($original) || (!is_array($analysis) && empty($plan['_managed_upgrade']))) {
            $result['status'] = 'failed';
            $result['issues'][] = 'migration_plan_invalid';
            return $result;
        }

        $newContents = !empty($plan['_managed_upgrade'])
            ? BlueskyOAuthHtaccessDiagnostics::replaceLegacyManagedBlock($original)
            : self::buildMigratedContents($analysis);
        if (!is_string($newContents)) {
            $result['status'] = 'failed';
            $result['issues'][] = 'migration_render_failed';
            return $result;
        }

        $permissions = (int) ($plan['_permissions'] ?? 0644);
        $backupId = '';
        try {
            if (!self::createBackup($rootDir, $original, $permissions, $backupId)) {
                $result['status'] = 'failed';
                $result['issues'][] = 'backup_failed';
                return $result;
            }
            $result['backup_created'] = true;
            $result['backup_id'] = $backupId;

            $current = @file_get_contents($path);
            if (!is_string($current) || !hash_equals($original, $current)) {
                $result['status'] = 'failed';
                $result['issues'][] = 'target_changed_after_backup';
                return $result;
            }

            if (!self::atomicReplace($path, $newContents, $permissions, $original)) {
                $result['status'] = 'failed';
                $result['issues'][] = 'write_failed';
                $rollback = self::restoreBackup($rootDir, $backupId, $original);
                $result['rolled_back'] = $rollback;
                if (!$rollback) {
                    $result['issues'][] = 'rollback_failed';
                }
                $result['current_status'] = self::safeStatus($rootDir);
                return $result;
            }

            $after = BlueskyOAuthHtaccessDiagnostics::inspect($rootDir);
            if (($after['status'] ?? '') !== 'managed'
                || empty($after['safe_for_managed_update'])
                || empty($after['static_backing_enabled'])
            ) {
                $result['status'] = 'failed';
                $result['issues'][] = 'post_write_validation_failed';
                $currentAfterWrite = @file_get_contents($path);
                $expectedCurrent = is_string($currentAfterWrite) ? $currentAfterWrite : null;
                $rollback = self::restoreBackup($rootDir, $backupId, $expectedCurrent);
                $result['rolled_back'] = $rollback;
                if (!$rollback) {
                    $result['issues'][] = 'rollback_failed';
                }
                $result['current_status'] = self::safeStatus($rootDir);
                return $result;
            }

            $result['status'] = 'migrated';
            $result['current_status'] = 'managed';
            $result['issues'] = [];
            return $result;
        } catch (\Throwable $exception) {
            $result['status'] = 'failed';
            $result['issues'][] = 'migration_failed';
            if ($backupId !== '') {
                $current = @file_get_contents($path);
                $rollback = self::restoreBackup($rootDir, $backupId, is_string($current) ? $current : null);
                $result['rolled_back'] = $rollback;
                if (!$rollback) {
                    $result['issues'][] = 'rollback_failed';
                }
                $result['current_status'] = self::safeStatus($rootDir);
            }
            return $result;
        }
    }

    /**
     * Restore a previously retained migration backup. The backup ID is opaque
     * to callers and is validated before it is used as a path component.
     *
     * @return array<string,mixed>
     */
    public static function rollback(string $rootDir, string $backupId): array
    {
        $result = [
            'status' => 'failed',
            'backup_id' => '',
            'restored' => false,
            'current_status' => self::safeStatus($rootDir),
            'issues' => [],
        ];
        if (preg_match('/\A' . preg_quote(self::BACKUP_PREFIX, '/') . '[a-f0-9-]+\z/', $backupId) !== 1) {
            $result['issues'][] = 'backup_id_invalid';
            return $result;
        }

        $backup = self::loadBackup($rootDir, $backupId);
        if ($backup === null) {
            $result['issues'][] = 'backup_unavailable';
            return $result;
        }
        $target = self::targetPath($rootDir);
        if (!is_file($target) || is_link($target)) {
            $result['issues'][] = 'target_unavailable';
            return $result;
        }
        if (!self::atomicReplace($target, $backup['contents'], $backup['permissions'], null)) {
            $result['issues'][] = 'rollback_failed';
            return $result;
        }
        $restored = @file_get_contents($target);
        if (!is_string($restored) || !hash_equals($backup['contents'], $restored)) {
            $result['issues'][] = 'rollback_verification_failed';
            return $result;
        }
        $result['status'] = 'rolled_back';
        $result['backup_id'] = $backupId;
        $result['restored'] = true;
        $result['current_status'] = self::safeStatus($rootDir);
        return $result;
    }

    /** @return array<string,mixed> */
    private static function buildPlan(string $rootDir): array
    {
        $path = self::targetPath($rootDir);
        $diagnostic = BlueskyOAuthHtaccessDiagnostics::inspect($rootDir);
        $publicDiagnostic = $diagnostic;
        unset($publicDiagnostic['path']);

        $result = [
            'status' => (string) ($diagnostic['status'] ?? 'invalid'),
            'previous_status' => (string) ($diagnostic['status'] ?? 'invalid'),
            'current_status' => (string) ($diagnostic['status'] ?? 'invalid'),
            'migration_mode' => 'none',
            'can_migrate' => false,
            'diagnostic' => $publicDiagnostic,
            'issues' => [],
            '_path' => $path,
            '_contents' => null,
            '_analysis' => null,
            '_managed_upgrade' => false,
            '_permissions' => 0644,
        ];

        if (($diagnostic['status'] ?? '') === 'managed' && !empty($diagnostic['safe_for_managed_update'])) {
            if (!empty($diagnostic['static_backing_enabled'])) {
                return $result;
            }
            if (empty($diagnostic['safe_for_static_backing_migration'])) {
                $result['status'] = 'blocked';
                $result['issues'][] = 'managed_block_not_safe_for_static_backing';
                return $result;
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                $result['status'] = 'blocked';
                $result['issues'][] = 'htaccess_read_failed';
                return $result;
            }
            $permissions = @fileperms($path);
            if ($permissions !== false) {
                $result['_permissions'] = $permissions & 0777;
            }
            $result['_contents'] = $contents;
            $result['_managed_upgrade'] = true;
            $result['migration_mode'] = 'managed_static_backing';
            $result['can_migrate'] = true;
            return $result;
        }
        if (!in_array(($diagnostic['status'] ?? ''), ['legacy', 'unmanaged'], true)) {
            $result['status'] = 'blocked';
            $result['issues'][] = 'diagnostic_status_not_migratable';
            self::appendIssues($result['issues'], (array) ($diagnostic['issues'] ?? []));
            return $result;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            $result['status'] = 'blocked';
            $result['issues'][] = 'htaccess_read_failed';
            return $result;
        }
        $analysis = self::analyze($contents);
        $result['_contents'] = $contents;
        $result['_analysis'] = $analysis;
        $permissions = @fileperms($path);
        if ($permissions !== false) {
            $result['_permissions'] = $permissions & 0777;
        }

        self::appendIssues($result['issues'], $analysis['issues']);
        $mode = ($diagnostic['status'] ?? '') === 'legacy' ? 'legacy' : 'unmanaged';
        $result['migration_mode'] = $mode;
        if ($mode === 'legacy') {
            if ((int) $analysis['metadata_exact_count'] !== 1 || (int) $analysis['jwks_exact_count'] !== 1) {
                $result['issues'][] = 'oauth_rule_incomplete_or_duplicate';
            }
            if ((int) $analysis['target_rule_count'] !== 2) {
                $result['issues'][] = 'oauth_rule_count_invalid';
            }
        } elseif ((int) $analysis['target_rule_count'] !== 0) {
            $result['issues'][] = 'oauth_rule_modified_or_unexpected';
        }

        $result['issues'] = array_values(array_unique($result['issues']));
        if ($result['issues'] === []) {
            $result['can_migrate'] = true;
        } else {
            $result['status'] = 'blocked';
        }
        return $result;
    }

    /** @param array<string,mixed> $plan */
    private static function sanitize(array $plan): array
    {
        foreach (array_keys($plan) as $key) {
            if (strpos((string) $key, '_') === 0) {
                unset($plan[$key]);
            }
        }
        return $plan;
    }

    /** @param array<int,string> $issues */
    private static function appendIssues(array &$issues, array $additional): void
    {
        foreach ($additional as $issue) {
            if (is_string($issue) && $issue !== '') {
                $issues[] = $issue;
            }
        }
        $issues = array_values(array_unique($issues));
    }

    private static function targetPath(string $rootDir): string
    {
        return rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
    }

    private static function safeStatus(string $rootDir): string
    {
        try {
            return (string) (BlueskyOAuthHtaccessDiagnostics::inspect($rootDir)['status'] ?? 'invalid');
        } catch (\Throwable $exception) {
            return 'invalid';
        }
    }

    /** @return array<string,mixed> */
    private static function analyze(string $contents): array
    {
        $lineEndings = [];
        preg_match_all('/\r\n|\n|\r/', $contents, $lineEndings);
        $uniqueEndings = array_values(array_unique($lineEndings[0] ?? []));
        $issues = [];
        if (count($uniqueEndings) > 1) {
            $issues[] = 'mixed_line_endings';
        }
        $eol = $uniqueEndings[0] ?? "\n";
        $lines = preg_split('/\r\n|\n|\r/', $contents);
        if (!is_array($lines)) {
            return [
                'lines' => [],
                'eol' => $eol,
                'target_rule_count' => 0,
                'metadata_exact_count' => 0,
                'jwks_exact_count' => 0,
                'catch_all_line' => 0,
                'issues' => ['htaccess_parse_failed'],
            ];
        }

        $moduleStack = [];
        $modRewriteBlocks = [];
        $currentBlock = null;
        $rewriteEngineLines = [];
        $catchAllLines = [];
        $targetRules = [];
        $beginCount = 0;
        $endCount = 0;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim((string) $line);
            if (strcasecmp($trimmed, BlueskyOAuthHtaccessDiagnostics::BEGIN_MARKER) === 0) {
                $beginCount++;
            }
            if (strcasecmp($trimmed, BlueskyOAuthHtaccessDiagnostics::END_MARKER) === 0) {
                $endCount++;
            }

            if (preg_match('/\A<IfModule\s+([^>]+)>\s*\z/i', $trimmed, $match) === 1) {
                $moduleName = strtolower(trim((string) $match[1]));
                if ($currentBlock !== null) {
                    $issues[] = 'complex_mod_rewrite_structure';
                }
                $moduleStack[] = $moduleName;
                if ($moduleName === 'mod_rewrite.c') {
                    $currentBlock = [
                        'start' => $lineNumber,
                        'end' => 0,
                        'index' => count($modRewriteBlocks),
                    ];
                    $modRewriteBlocks[] = $currentBlock;
                }
                continue;
            }
            if (preg_match('/\A<\/IfModule>\s*\z/i', $trimmed) === 1) {
                if ($moduleStack === []) {
                    $issues[] = 'ifmodule_close_without_open';
                    continue;
                }
                $moduleName = array_pop($moduleStack);
                if ($moduleName === 'mod_rewrite.c') {
                    if ($currentBlock === null) {
                        $issues[] = 'mod_rewrite_block_invalid';
                    } else {
                        $currentBlock['end'] = $lineNumber;
                        $modRewriteBlocks[$currentBlock['index']] = $currentBlock;
                        $currentBlock = null;
                    }
                }
                continue;
            }

            $rule = self::parseRewriteRule($trimmed);
            if ($rule !== null) {
                $target = self::targetKind($rule['pattern']);
                if ($target !== null) {
                    $exactLine = $trimmed === ($target === 'metadata'
                        ? BlueskyOAuthHtaccessDiagnostics::METADATA_RULE
                        : BlueskyOAuthHtaccessDiagnostics::JWKS_RULE);
                    $targetRules[] = [
                        'kind' => $target,
                        'line' => $lineNumber,
                        'exact' => $exactLine,
                        'block' => $currentBlock === null ? -1 : (int) $currentBlock['index'],
                    ];
                }
                if ($currentBlock !== null && self::isGenericCatchAll($rule['pattern'])) {
                    $catchAllLines[] = $lineNumber;
                }
            }
            if ($currentBlock === null) {
                continue;
            }
            if (preg_match('/\ARewriteEngine\s+On\s*\z/i', $trimmed) === 1) {
                $rewriteEngineLines[] = $lineNumber;
            }
        }

        if ($moduleStack !== [] || $currentBlock !== null) {
            $issues[] = 'ifmodule_structure_invalid';
        }
        if ($beginCount !== 0 || $endCount !== 0) {
            $issues[] = 'marker_present_or_damaged';
        }
        if (count($modRewriteBlocks) !== 1) {
            $issues[] = 'mod_rewrite_block_not_unique';
        }
        if (count($rewriteEngineLines) !== 1) {
            $issues[] = 'rewrite_engine_not_unique';
        }
        if (count($catchAllLines) !== 1) {
            $issues[] = 'generic_catch_all_not_unique';
        }

        $metadataExactCount = 0;
        $jwksExactCount = 0;
        foreach ($targetRules as $rule) {
            if ($rule['block'] < 0) {
                $issues[] = 'oauth_rule_outside_mod_rewrite';
            }
            if (!$rule['exact']) {
                $issues[] = 'oauth_rule_modified';
            } elseif ($rule['kind'] === 'metadata') {
                $metadataExactCount++;
            } else {
                $jwksExactCount++;
            }
        }

        $block = $modRewriteBlocks[0] ?? ['start' => 0, 'end' => 0, 'index' => -1];
        $engineLine = $rewriteEngineLines[0] ?? 0;
        $catchAllLine = $catchAllLines[0] ?? 0;
        if ($engineLine === 0 || $catchAllLine === 0
            || $engineLine <= (int) $block['start']
            || $catchAllLine >= (int) $block['end']
            || $engineLine >= $catchAllLine
        ) {
            $issues[] = 'rewrite_insertion_position_ambiguous';
        }
        foreach ($targetRules as $rule) {
            if ($catchAllLine > 0 && (int) $rule['line'] > $catchAllLine) {
                $issues[] = 'oauth_rule_after_generic_catch_all';
            }
            if ((int) $rule['block'] !== (int) $block['index']) {
                $issues[] = 'oauth_rule_block_mismatch';
            }
        }

        return [
            'lines' => $lines,
            'eol' => $eol,
            'target_rule_count' => count($targetRules),
            'metadata_exact_count' => $metadataExactCount,
            'jwks_exact_count' => $jwksExactCount,
            'catch_all_line' => $catchAllLine,
            'target_rules' => $targetRules,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @return array{pattern:string,substitution:string,flags:string}|null */
    private static function parseRewriteRule(string $line): ?array
    {
        if (preg_match('/\ARewriteRule\s+(\S+)\s+(\S+)(?:\s+(\[[^]]*\]))?\s*\z/i', $line, $match) !== 1) {
            return null;
        }
        return [
            'pattern' => (string) $match[1],
            'substitution' => (string) $match[2],
            'flags' => (string) ($match[3] ?? ''),
        ];
    }

    private static function targetKind(string $pattern): ?string
    {
        if ($pattern === '^oauth-client-metadata\\.json$') {
            return 'metadata';
        }
        if ($pattern === '^\\.well-known/tomos-bluesky-jwks\\.json$') {
            return 'jwks';
        }
        return null;
    }

    private static function isGenericCatchAll(string $pattern): bool
    {
        return in_array($pattern, ['^', '^.*$', '^(.*)$', '^(.*)'], true);
    }

    private static function buildMigratedContents(array $analysis): ?string
    {
        $lines = $analysis['lines'] ?? null;
        $eol = $analysis['eol'] ?? "\n";
        $targetRules = $analysis['target_rules'] ?? [];
        if (!is_array($lines) || !is_string($eol) || !is_array($targetRules)) {
            return null;
        }
        $targetLines = [];
        foreach ($targetRules as $rule) {
            if (!is_array($rule) || !isset($rule['line'])) {
                return null;
            }
            $targetLines[] = (int) $rule['line'];
        }
        $targetLines = array_values(array_unique($targetLines));
        sort($targetLines, SORT_NUMERIC);
        $insertBefore = $targetLines !== [] ? $targetLines[0] : (int) ($analysis['catch_all_line'] ?? 0);
        if ($insertBefore < 1) {
            return null;
        }
        $remove = array_fill_keys($targetLines, true);
        $block = BlueskyOAuthHtaccessDiagnostics::managedBlockLines();
        $output = [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if ($lineNumber === $insertBefore) {
                foreach ($block as $blockLine) {
                    $output[] = $blockLine;
                }
            }
            if (isset($remove[$lineNumber])) {
                continue;
            }
            $output[] = (string) $line;
        }
        return implode($eol, $output);
    }

    private static function createBackup(string $rootDir, string $contents, int $permissions, string &$backupId): bool
    {
        $base = self::safeBackupBase($rootDir);
        if ($base === null || !is_writable($base)) {
            return false;
        }
        try {
            $backupId = self::BACKUP_PREFIX . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return false;
        }
        $directory = $base . DIRECTORY_SEPARATOR . $backupId;
        $files = $directory . DIRECTORY_SEPARATOR . 'files';
        if (!@mkdir($files, 0700, true)) {
            return false;
        }
        $backupPath = $files . DIRECTORY_SEPARATOR . '.htaccess';
        $metadataPath = $directory . DIRECTORY_SEPARATOR . 'metadata.json';
        $metadata = json_encode([
            'kind' => 'bluesky_oauth_htaccess',
            'sha256' => hash('sha256', $contents),
            'permissions' => $permissions,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($metadata)
            || @file_put_contents($backupPath, $contents, LOCK_EX) === false
            || !hash_equals(hash('sha256', $contents), (string) @hash_file('sha256', $backupPath))
            || @file_put_contents($metadataPath, $metadata . "\n", LOCK_EX) === false
        ) {
            self::removeTree($directory);
            $backupId = '';
            return false;
        }
        @chmod($backupPath, $permissions);
        return true;
    }

    /** @return array{contents:string,permissions:int}|null */
    private static function loadBackup(string $rootDir, string $backupId): ?array
    {
        $base = self::safeBackupBase($rootDir);
        if ($base === null) {
            return null;
        }
        $directory = $base . DIRECTORY_SEPARATOR . $backupId;
        if (!is_dir($directory) || is_link($directory)) {
            return null;
        }
        $metadata = json_decode((string) @file_get_contents($directory . DIRECTORY_SEPARATOR . 'metadata.json'), true);
        $backupPath = $directory . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . '.htaccess';
        $contents = @file_get_contents($backupPath);
        if (!is_array($metadata) || ($metadata['kind'] ?? '') !== 'bluesky_oauth_htaccess'
            || !is_string($contents)
            || !is_string($metadata['sha256'] ?? null)
            || !hash_equals((string) $metadata['sha256'], hash('sha256', $contents))
        ) {
            return null;
        }
        return [
            'contents' => $contents,
            'permissions' => (int) ($metadata['permissions'] ?? 0644),
        ];
    }

    private static function safeBackupBase(string $rootDir): ?string
    {
        $rootReal = realpath($rootDir);
        if ($rootReal === false || !is_dir($rootReal) || is_link($rootDir)) {
            return null;
        }
        $base = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::BACKUP_ROOT);
        $storage = dirname($base);
        if (!is_dir($storage) || is_link($storage) || !is_dir($base) || is_link($base)) {
            return null;
        }
        $baseReal = realpath($base);
        if ($baseReal === false || ($baseReal !== $rootReal && strpos($baseReal, $rootReal . DIRECTORY_SEPARATOR) !== 0)) {
            return null;
        }
        return $base;
    }

    private static function restoreBackup(string $rootDir, string $backupId, ?string $expectedCurrent): bool
    {
        $backup = self::loadBackup($rootDir, $backupId);
        $path = self::targetPath($rootDir);
        if ($backup === null || !is_file($path) || is_link($path)) {
            return false;
        }
        if (!self::atomicReplace($path, $backup['contents'], $backup['permissions'], $expectedCurrent)) {
            return false;
        }
        $restored = @file_get_contents($path);
        return is_string($restored) && hash_equals($backup['contents'], $restored);
    }

    private static function atomicReplace(string $path, string $contents, int $permissions, ?string $expectedCurrent): bool
    {
        if (!is_file($path) || is_link($path) || !is_writable(dirname($path))) {
            return false;
        }
        $current = @file_get_contents($path);
        if (!is_string($current) || ($expectedCurrent !== null && !hash_equals($expectedCurrent, $current))) {
            return false;
        }
        try {
            $temporary = $path . '.tomos-oauth-' . bin2hex(random_bytes(8)) . '.tmp';
        } catch (\Throwable $exception) {
            return false;
        }
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            @unlink($temporary);
            return false;
        }
        $written = @file_get_contents($temporary);
        if (!is_string($written) || !hash_equals($contents, $written)) {
            @unlink($temporary);
            return false;
        }
        @chmod($temporary, $permissions);
        if (is_link($path) || !@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) @scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
