<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tools/UpdateFileSet.php';
$updateService = (string) file_get_contents($root . '/core/UpdateService.php');
$selfUpdate = (string) file_get_contents($root . '/core/UpdaterSelfUpdate.php');
$builder = (string) file_get_contents($root . '/tools/build-update-package.php');
$fileSet = (string) file_get_contents($root . '/tools/UpdateFileSet.php');
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

foreach (['cache/.htaccess', 'storage/.htaccess', 'trash/.htaccess'] as $target) {
    $name = substr($target, 0, strpos($target, '/'));
    checkProtected(in_array($target, $required, true), $target . ' is a required installed file');
    checkProtected(strpos($selfUpdate, "'" . $target . "' => [") !== false, $target . ' is a fixed self-update target');
    checkProtected(strpos($builder, "'" . $target . "' => [") !== false, $target . ' is a fixed builder target');
    checkProtected(strpos($builder, "'pending' => 'core/updater-pending/" . $name . "-htaccess'") !== false, $target . ' has a fixed pending payload');
    checkProtected(strpos($fileSet, "'" . $target . "'") !== false, $target . ' is represented by the derived update set');
    checkProtected(UpdateFileSet::isProtectedPath($target), $target . ' remains protected from general Update');
    checkProtected(!UpdateFileSet::isAllowedUpdatePath($target), $target . ' is not generally updateable');
}

checkProtected(
    strpos($updateService, "preg_match('#\\Acore/Update(?:Lock|Service|Exception)\\.php\\z#', \$path)") !== false,
    'UpdateService keeps updater core protected from direct replacement'
);
checkProtected(strpos($updateService, "\$path === 'docs/theme/theme-rules.json'") !== false, 'UpdateService allows the Theme rules runtime dependency');
checkProtected(strpos($builder, "\$path === 'docs/theme/theme-rules.json'") !== false, 'builder allows the Theme rules runtime dependency');
checkProtected(strpos($fileSet, "\$path === 'docs/theme/theme-rules.json'") !== false, 'derived update file set allows the Theme rules runtime dependency');
checkProtected(strpos($builder, "'pending' => 'core/updater-pending/theme-rules.json'") !== false, 'builder has Theme rules pending payload path');
checkProtected(strpos($builder, "'metadata' => 'core/updater-pending/theme-rules.meta.json'") !== false, 'builder has Theme rules pending metadata path');
checkProtected(strpos($selfUpdate, "'pending_file' => 'theme-rules.json'") !== false, 'self-update consumes Theme rules pending payload');
checkProtected(strpos($selfUpdate, "'metadata_file' => 'theme-rules.meta.json'") !== false, 'self-update consumes Theme rules pending metadata');
checkProtected(strpos($builder, "'pending' => 'core/updater-pending/update-lock.php'") !== false, 'builder has UpdateLock pending PHP path');
checkProtected(strpos($builder, "'metadata' => 'core/updater-pending/update-lock.json'") !== false, 'builder has UpdateLock pending metadata path');
checkProtected(strpos($selfUpdate, "'pending_file' => 'update-lock.php'") !== false, 'self-update consumes UpdateLock pending PHP');
checkProtected(strpos($selfUpdate, "'metadata_file' => 'update-lock.json'") !== false, 'self-update consumes UpdateLock pending metadata');

echo "update_protected_target_consistency_check: {$passes} checks passed\n";
