<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$updateService = (string) file_get_contents($root . '/core/UpdateService.php');
$selfUpdate = (string) file_get_contents($root . '/core/UpdaterSelfUpdate.php');
$builder = (string) file_get_contents($root . '/tools/build-update-package.php');
$required = file($root . '/core/required-installed-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

$passes = 0;
function checkProtected(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

foreach (['core/UpdateLock.php', 'core/UpdateService.php'] as $target) {
    checkProtected(in_array($target, $required, true), $target . ' is a required installed file');
    checkProtected(strpos($selfUpdate, "'" . $target . "' => [") !== false, $target . ' is an atomic self-update target');
    checkProtected(strpos($builder, "'" . $target . "' => [") !== false, $target . ' is staged by the package builder');
}

checkProtected(
    strpos($updateService, "preg_match('#\\Acore/Update(?:Lock|Service|Exception)\\.php\\z#', \$path)") !== false,
    'UpdateService keeps updater core protected from direct replacement'
);
checkProtected(strpos($builder, "'pending' => 'core/updater-pending/update-lock.php'") !== false, 'builder has UpdateLock pending PHP path');
checkProtected(strpos($builder, "'metadata' => 'core/updater-pending/update-lock.json'") !== false, 'builder has UpdateLock pending metadata path');
checkProtected(strpos($selfUpdate, "'pending_file' => 'update-lock.php'") !== false, 'self-update consumes UpdateLock pending PHP');
checkProtected(strpos($selfUpdate, "'metadata_file' => 'update-lock.json'") !== false, 'self-update consumes UpdateLock pending metadata');

echo "update_protected_target_consistency_check: {$passes} checks passed\n";
