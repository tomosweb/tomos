<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthHtaccessDiagnostics
{
    public const BEGIN_MARKER = '# BEGIN TOMOS MANAGED OAUTH';
    public const END_MARKER = '# END TOMOS MANAGED OAUTH';

    public const METADATA_RULE = 'RewriteRule ^oauth-client-metadata\\.json$ oauth-client-metadata.json.php [L]';
    public const JWKS_RULE = 'RewriteRule ^\\.well-known/tomos-bluesky-jwks\\.json$ tomos-bluesky-jwks.json.php [L]';
    public const STATIC_METADATA_REQUEST_CONDITION = 'RewriteCond %{REQUEST_FILENAME} ^(.+)/oauth-client-metadata\\.json\\.php$';
    public const STATIC_METADATA_FILE_CONDITION = 'RewriteCond %1/oauth-client-metadata.static.json -f';
    public const STATIC_METADATA_RULE = 'RewriteRule ^oauth-client-metadata\\.json\\.php$ oauth-client-metadata.static.json [END]';
    public const STATIC_JWKS_REQUEST_CONDITION = 'RewriteCond %{REQUEST_FILENAME} ^(.+)/tomos-bluesky-jwks\\.json\\.php$';
    public const STATIC_JWKS_FILE_CONDITION = 'RewriteCond %1/tomos-bluesky-jwks.static.json -f';
    public const STATIC_JWKS_RULE = 'RewriteRule ^tomos-bluesky-jwks\\.json\\.php$ tomos-bluesky-jwks.static.json [END]';

    /** @return array<int,string> */
    public static function managedBlockLines(): array
    {
        return [
            self::BEGIN_MARKER,
            '# AT Protocol OAuth public metadata endpoints. These must return JSON directly',
            '# from the exact URL declared as client_id / jwks_uri.',
            '# Prefer static backing at those legacy URLs when it is available.',
            self::STATIC_METADATA_REQUEST_CONDITION,
            self::STATIC_METADATA_FILE_CONDITION,
            self::STATIC_METADATA_RULE,
            self::STATIC_JWKS_REQUEST_CONDITION,
            self::STATIC_JWKS_FILE_CONDITION,
            self::STATIC_JWKS_RULE,
            self::METADATA_RULE,
            self::JWKS_RULE,
            self::END_MARKER,
        ];
    }

    /** @return array<int,string> */
    public static function legacyManagedBlockLines(): array
    {
        return [
            self::BEGIN_MARKER,
            '# AT Protocol OAuth public metadata endpoints. These must return JSON directly',
            '# from the exact URL declared as client_id / jwks_uri.',
            self::METADATA_RULE,
            self::JWKS_RULE,
            self::END_MARKER,
        ];
    }

    /**
     * Replace only the exact Phase 2 managed block with the static-backing
     * version. Arbitrary marked content is deliberately not rewritten.
     */
    public static function replaceLegacyManagedBlock(string $contents): ?string
    {
        if (self::managedBlockVersion($contents) !== 'legacy') {
            return null;
        }
        $eol = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
        $legacy = implode($eol, self::legacyManagedBlockLines());
        if (substr_count($contents, $legacy) !== 1) {
            return null;
        }
        return str_replace($legacy, implode($eol, self::managedBlockLines()), $contents);
    }

    /**
     * Inspect the install-root .htaccess without changing it.
     *
     * @return array<string,mixed>
     */
    public static function inspect(string $rootDir): array
    {
        $path = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
        $result = self::baseResult($path);

        if (is_link($path)) {
            $result['status'] = 'symlink';
            $result['issues'][] = 'htaccess_symlink';
            return $result;
        }
        if (!is_file($path)) {
            $result['status'] = 'missing';
            $result['issues'][] = file_exists($path) ? 'htaccess_not_regular_file' : 'htaccess_missing';
            return $result;
        }
        if (!is_readable($path)) {
            $result['status'] = 'unreadable';
            $result['issues'][] = 'htaccess_unreadable';
            return $result;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            $result['status'] = 'unreadable';
            $result['issues'][] = 'htaccess_read_failed';
            return $result;
        }

        $analysis = self::analyze($contents);
        foreach ($analysis as $key => $value) {
            $result[$key] = $value;
        }
        $result['managed_block_version'] = self::managedBlockVersion($contents);
        $result['static_backing_enabled'] = $result['managed_block_version'] === 'static';

        if ($result['structural_issues'] !== []) {
            $result['status'] = 'invalid';
            $result['issues'] = array_merge($result['issues'], $result['structural_issues']);
            return self::finalize($result);
        }

        if ($result['begin_count'] !== 0 || $result['end_count'] !== 0) {
            if ($result['begin_count'] !== 1 || $result['end_count'] !== 1 || $result['marker_end_line'] <= $result['marker_begin_line']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'marker_structure_invalid';
                return self::finalize($result);
            }
            if (!$result['marker_in_mod_rewrite']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'marker_outside_mod_rewrite';
            }
            if (!$result['marker_end_in_mod_rewrite']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'marker_end_outside_mod_rewrite';
            }
            if (!$result['marker_after_rewrite_engine']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'marker_before_rewrite_engine';
            }
            if (!$result['marker_before_generic_catch_all']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'marker_after_generic_catch_all';
            }
            if (!$result['metadata_rule_in_marker']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'metadata_rule_not_managed';
            }
            if (!$result['jwks_rule_in_marker']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'jwks_rule_not_managed';
            }
            if ($result['metadata_rule_count'] > 1) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'metadata_rule_duplicate';
            }
            if ($result['jwks_rule_count'] > 1) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'jwks_rule_duplicate';
            }
            if ($result['metadata_rule_outside_marker']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'metadata_rule_outside_marker';
            }
            if ($result['jwks_rule_outside_marker']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'jwks_rule_outside_marker';
            }
            if ($result['metadata_rule_modified']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'metadata_rule_modified';
            }
            if ($result['jwks_rule_modified']) {
                $result['status'] = 'invalid';
                $result['issues'][] = 'jwks_rule_modified';
            }
            if ($result['structural_issues'] !== []) {
                $result['status'] = 'invalid';
                foreach ($result['structural_issues'] as $issue) {
                    $result['issues'][] = $issue;
                }
            }
            if ($result['status'] === 'unknown') {
                $result['status'] = 'managed';
            }
            return self::finalize($result);
        }

        $result['status'] = ($result['has_metadata_rule'] || $result['has_jwks_rule']) ? 'legacy' : 'unmanaged';
        if ($result['has_metadata_rule'] || $result['has_jwks_rule']) {
            $result['issues'][] = 'marker_missing';
        } else {
            $result['issues'][] = 'oauth_rules_missing';
        }
        return self::finalize($result);
    }

    /**
     * @return array<string,mixed>
     */
    private static function baseResult(string $path): array
    {
        return [
            'status' => 'unknown',
            'path' => $path,
            'has_marker' => false,
            'begin_count' => 0,
            'end_count' => 0,
            'marker_begin_line' => 0,
            'marker_end_line' => 0,
            'marker_in_mod_rewrite' => false,
            'marker_end_in_mod_rewrite' => false,
            'marker_after_rewrite_engine' => false,
            'marker_before_generic_catch_all' => false,
            'has_metadata_rule' => false,
            'has_jwks_rule' => false,
            'metadata_rule_count' => 0,
            'jwks_rule_count' => 0,
            'metadata_rule_in_marker' => false,
            'jwks_rule_in_marker' => false,
            'metadata_rule_outside_marker' => false,
            'jwks_rule_outside_marker' => false,
            'metadata_rule_modified' => false,
            'jwks_rule_modified' => false,
            'oauth_rule_after_generic_catch_all' => false,
            'structural_issues' => [],
            'safe_for_managed_update' => false,
            'managed_block_version' => 'none',
            'static_backing_enabled' => false,
            'safe_for_static_backing_migration' => false,
            'issues' => [],
        ];
    }

    private static function managedBlockVersion(string $contents): string
    {
        $eol = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
        $current = implode($eol, self::managedBlockLines());
        if (substr_count($contents, $current) === 1) {
            return 'static';
        }
        $legacy = implode($eol, self::legacyManagedBlockLines());
        return substr_count($contents, $legacy) === 1 ? 'legacy' : 'unknown';
    }

    /**
     * @return array<string,mixed>
     */
    private static function analyze(string $contents): array
    {
        $lines = preg_split('/\\R/', $contents);
        if (!is_array($lines)) {
            return [
                'structural_issues' => ['htaccess_parse_failed'],
                'issues' => [],
            ];
        }

        $beginLines = [];
        $endLines = [];
        $metadataRules = [];
        $jwksRules = [];
        $genericCatchAllLines = [];
        $rewriteEngineLines = [];
        $issues = [];
        $moduleStack = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim((string) $line);
            if (strcasecmp($trimmed, self::BEGIN_MARKER) === 0) {
                $beginLines[] = $lineNumber;
            }
            if (strcasecmp($trimmed, self::END_MARKER) === 0) {
                $endLines[] = $lineNumber;
            }

            if (preg_match('/\\A<IfModule\\s+([^>]+)>\\s*\\z/i', $trimmed, $match) === 1) {
                $moduleStack[] = strtolower(trim((string) $match[1]));
                continue;
            }
            if (preg_match('/\\A<\\/IfModule>\\s*\\z/i', $trimmed) === 1) {
                if ($moduleStack === []) {
                    $issues[] = 'ifmodule_close_without_open';
                } else {
                    array_pop($moduleStack);
                }
                continue;
            }

            $inRewriteModule = in_array('mod_rewrite.c', $moduleStack, true);
            if (!$inRewriteModule) {
                continue;
            }
            if (preg_match('/\\ARewriteEngine\\s+On\\s*\\z/i', $trimmed) === 1) {
                $rewriteEngineLines[] = $lineNumber;
            }
            $rule = self::parseRewriteRule($trimmed);
            if ($rule === null) {
                continue;
            }
            if ($rule['pattern'] === '^oauth-client-metadata\\.json$' && $rule['substitution'] === 'oauth-client-metadata.json.php') {
                $metadataRules[] = [
                    'line' => $lineNumber,
                    'exact' => $trimmed === self::METADATA_RULE,
                    'in_mod_rewrite' => true,
                ];
            }
            if ($rule['pattern'] === '^\\.well-known/tomos-bluesky-jwks\\.json$' && $rule['substitution'] === 'tomos-bluesky-jwks.json.php') {
                $jwksRules[] = [
                    'line' => $lineNumber,
                    'exact' => $trimmed === self::JWKS_RULE,
                    'in_mod_rewrite' => true,
                ];
            }
            if (self::isGenericCatchAll($rule['pattern'])) {
                $genericCatchAllLines[] = $lineNumber;
            }
        }

        if ($moduleStack !== []) {
            $issues[] = 'ifmodule_open_without_close';
        }

        $beginLine = count($beginLines) === 1 ? $beginLines[0] : 0;
        $endLine = count($endLines) === 1 ? $endLines[0] : 0;
        $hasMarker = $beginLine > 0 || $endLine > 0;
        $metadataCount = count($metadataRules);
        $jwksCount = count($jwksRules);
        $metadataInMarker = false;
        $jwksInMarker = false;
        $metadataOutsideMarker = false;
        $jwksOutsideMarker = false;
        if ($beginLine > 0 && $endLine > $beginLine) {
            foreach ($metadataRules as $rule) {
                if ($rule['line'] > $beginLine && $rule['line'] < $endLine) {
                    $metadataInMarker = true;
                } else {
                    $metadataOutsideMarker = true;
                }
            }
            foreach ($jwksRules as $rule) {
                if ($rule['line'] > $beginLine && $rule['line'] < $endLine) {
                    $jwksInMarker = true;
                } else {
                    $jwksOutsideMarker = true;
                }
            }
        }

        $firstCatchAll = $genericCatchAllLines[0] ?? null;
        $lastOauthRule = 0;
        foreach (array_merge($metadataRules, $jwksRules) as $rule) {
            $lastOauthRule = max($lastOauthRule, (int) $rule['line']);
        }
        $markerAfterEngine = $beginLine > 0 && $rewriteEngineLines !== [] && min($rewriteEngineLines) < $beginLine;
        $markerBeforeCatchAll = $firstCatchAll === null || ($endLine > 0 && $endLine < $firstCatchAll);

        $issues = array_values(array_unique($issues));
        return [
            'has_marker' => $hasMarker,
            'begin_count' => count($beginLines),
            'end_count' => count($endLines),
            'marker_begin_line' => $beginLine,
            'marker_end_line' => $endLine,
            'marker_in_mod_rewrite' => $beginLine > 0 && self::lineInRewriteModule($lines, $beginLine),
            'marker_end_in_mod_rewrite' => $endLine > 0 && self::lineInRewriteModule($lines, $endLine),
            'marker_after_rewrite_engine' => $markerAfterEngine,
            'marker_before_generic_catch_all' => $markerBeforeCatchAll,
            'has_metadata_rule' => $metadataCount > 0,
            'has_jwks_rule' => $jwksCount > 0,
            'metadata_rule_count' => $metadataCount,
            'jwks_rule_count' => $jwksCount,
            'metadata_rule_in_marker' => $metadataInMarker,
            'jwks_rule_in_marker' => $jwksInMarker,
            'metadata_rule_outside_marker' => $metadataOutsideMarker,
            'jwks_rule_outside_marker' => $jwksOutsideMarker,
            'metadata_rule_modified' => self::hasModifiedRule($metadataRules),
            'jwks_rule_modified' => self::hasModifiedRule($jwksRules),
            'oauth_rule_after_generic_catch_all' => $firstCatchAll !== null && $lastOauthRule > $firstCatchAll,
            'structural_issues' => $issues,
            'issues' => [],
        ];
    }

    /** @return array{pattern:string,substitution:string,flags:string}|null */
    private static function parseRewriteRule(string $line): ?array
    {
        if (preg_match('/\\ARewriteRule\\s+(\\S+)\\s+(\\S+)(?:\\s+(\\[[^]]*\\]))?\\s*\\z/i', $line, $match) !== 1) {
            return null;
        }
        return [
            'pattern' => (string) $match[1],
            'substitution' => (string) $match[2],
            'flags' => (string) ($match[3] ?? ''),
        ];
    }

    private static function isGenericCatchAll(string $pattern): bool
    {
        return in_array($pattern, ['^', '^.*$', '^(.*)$', '^(.*)'], true);
    }

    /** @param array<int,array<string,mixed>> $rules */
    private static function hasModifiedRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (empty($rule['exact'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $lines */
    private static function lineInRewriteModule(array $lines, int $lineNumber): bool
    {
        $stack = [];
        foreach ($lines as $index => $line) {
            $currentLine = $index + 1;
            $trimmed = trim($line);
            if (preg_match('/\\A<IfModule\\s+([^>]+)>\\s*\\z/i', $trimmed, $match) === 1) {
                $stack[] = strtolower(trim((string) $match[1]));
            } elseif (preg_match('/\\A<\\/IfModule>\\s*\\z/i', $trimmed) === 1 && $stack !== []) {
                array_pop($stack);
            }
            if ($currentLine === $lineNumber) {
                return in_array('mod_rewrite.c', $stack, true);
            }
        }
        return false;
    }

    /** @param array<string,mixed> $result */
    private static function finalize(array $result): array
    {
        $result['issues'] = array_values(array_unique(array_merge(
            is_array($result['issues'] ?? null) ? $result['issues'] : [],
            !empty($result['oauth_rule_after_generic_catch_all']) ? ['oauth_rule_after_generic_catch_all'] : []
        )));
        $result['safe_for_managed_update'] = $result['status'] === 'managed'
            && !empty($result['marker_in_mod_rewrite'])
            && !empty($result['marker_end_in_mod_rewrite'])
            && !empty($result['marker_after_rewrite_engine'])
            && !empty($result['marker_before_generic_catch_all'])
            && !empty($result['metadata_rule_in_marker'])
            && !empty($result['jwks_rule_in_marker'])
            && empty($result['metadata_rule_outside_marker'])
            && empty($result['jwks_rule_outside_marker'])
            && empty($result['metadata_rule_modified'])
            && empty($result['jwks_rule_modified'])
            && empty($result['oauth_rule_after_generic_catch_all'])
            && $result['begin_count'] === 1
            && $result['end_count'] === 1;
        $result['safe_for_static_backing_migration'] = $result['safe_for_managed_update']
            && ($result['managed_block_version'] ?? '') === 'legacy';
        return $result;
    }
}
