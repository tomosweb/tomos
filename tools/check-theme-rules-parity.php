<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/core/ThemeRules.php';

$options = getopt('', ['browser:']);
$browserPath = trim((string) ($options['browser'] ?? ''));
if ($browserPath === '' || !is_file($browserPath) || !is_readable($browserPath)) {
    fwrite(STDERR, "Usage: php tools/check-theme-rules-parity.php --browser=/path/to/theme-validator.js\n");
    exit(1);
}

$browser = (string) file_get_contents($browserPath);
if (preg_match('/\\bRULES_HASH\\s*=\\s*[\'\"]([a-f0-9]{64})[\'\"]/i', $browser, $matches) !== 1) {
    fwrite(STDERR, "Browser rule artifact does not declare RULES_HASH.\n");
    exit(1);
}

$coreHash = Tomos\ThemeRules::sha256();
$browserHash = strtolower((string) $matches[1]);
if (!hash_equals($coreHash, $browserHash)) {
    fwrite(STDERR, "Theme rule hash mismatch: Core {$coreHash}, Browser {$browserHash}.\n");
    exit(1);
}

echo "theme_rules_parity: OK {$coreHash}\n";
