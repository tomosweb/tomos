<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/update/index.php');
$docs = (string) file_get_contents($root . '/docs/install/tomos-update.md');
$passes = 0;

function checkPhase1d(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

foreach ([
    'value="inspect_online"' => 'online inspect action exists',
    'new Tomos\\UpdateReleaseProvider()' => 'GET and POST use the release provider',
    'new Tomos\\UpdatePackageDownloader()' => 'online POST uses the package downloader',
    'stageDownloadedPackage(' => 'online POST uses formal UpdateService staging',
    'finally {' => 'download temporary file has finally cleanup',
    '$_SESSION[\'tomos_update_package\']' => 'online flow uses the common staging session key',
    '$onlineError' => 'online errors are separate from manual update errors',
    '手動の更新ZIPは引き続き利用できます' => 'online failure preserves the manual route',
    '現在利用できるオンライン更新はありません' => 'no-update wording avoids an unsupported latest claim',
    'e($currentVersion) . \' → \' . e((string) $releaseInfo[\'next_version\'])' => 'current to next version path is represented in the UI flow',
    '更新ZIPを使用する' => 'manual update section remains visible',
 ] as $needle => $message) {
    checkPhase1d(strpos($page, $needle) !== false, $message);
}

$postBranch = strpos($page, "if (\$_SERVER['REQUEST_METHOD'] === 'POST'");
$downloaderUse = strpos($page, 'new Tomos\\UpdatePackageDownloader()');
checkPhase1d($postBranch !== false && $downloaderUse !== false && $downloaderUse > $postBranch, 'ZIP downloader is not used before POST handling');
$catalogUses = substr_count($page, 'new Tomos\\UpdateReleaseProvider()');
checkPhase1d($catalogUses >= 2, 'catalog is checked on GET and re-fetched on POST');
checkPhase1d(strpos($page, '(string) $releaseInfo[\'package_url\']') !== false, 'POST uses the freshly fetched package URL');
checkPhase1d(strpos($docs, '手動ZIP更新は恒久的な正式ルートとして残ります') !== false, 'user documentation preserves manual updates');
checkPhase1d(strpos($docs, '自動更新、バックグラウンド更新、一括多段更新は行いません') !== false, 'user documentation describes explicit confirmation');

echo "update_phase1d_check: {$passes} checks passed\n";
