<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ThemeRules.php';
require_once dirname(__DIR__) . '/core/ThemeValidator.php';

use Tomos\ThemeRules;
use Tomos\ThemeValidator;

function previewCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$rules = ThemeRules::all();
$rulesHash = ThemeRules::sha256();
$canonical = ThemeRules::canonicalJson();
$decodedCanonical = json_decode($canonical, true);
previewCheck(preg_match('/\A[a-f0-9]{64}\z/', $rulesHash) === 1, 'rules hash must be a SHA-256 value');
previewCheck(is_array($decodedCanonical), 'canonical rules JSON must remain valid JSON');
$required = ThemeRules::requiredFiles();
$starter = $root . '/theme-packages/tomos-starter/package';
$starterCopy = sys_get_temp_dir() . '/tomos-starter-validation-' . bin2hex(random_bytes(6));
mkdir($starterCopy . '/tomos-starter', 0755, true);
file_put_contents($starterCopy . '/VERSION', "0.6.1\n");

try {
    foreach ($required as $relative) {
        $source = $starter . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $starterCopy . '/tomos-starter/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        previewCheck(copy($source, $destination), 'could not copy ' . $relative);
    }

    $result = (new ThemeValidator($starterCopy))->validate('tomos-starter');
    previewCheck($result['valid'] === true, 'starter theme must pass Core ThemeValidator');
    previewCheck(str_contains((string) file_get_contents($starter . '/templates/layout.html'), '{{{ page.seo_head_html }}}'), 'starter SEO placeholder missing');
    previewCheck(($rules['compatibility']['tomos'] ?? '') === '0.6.1', 'baseline drifted');
    previewCheck(($rules['developer_preview']['limits']['max_zip_bytes'] ?? 0) === 10485760, 'ZIP limit missing');
    echo "theme_developer_preview_check: OK\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($starterCopy, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    @rmdir($starterCopy);
}
