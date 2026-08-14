<?php

declare(strict_types=1);

require_once __DIR__ . '/InstallManifest.php';
require_once __DIR__ . '/InstallerPublicKey.php';
require_once __DIR__ . '/InstallerSecurity.php';
require_once __DIR__ . '/InstallerDownloader.php';
require_once __DIR__ . '/InstallerStaging.php';
require_once __DIR__ . '/InstallerCore.php';
require_once __DIR__ . '/InstallerVerifiedResult.php';
require_once __DIR__ . '/InstallerJournal.php';
require_once __DIR__ . '/InstallerPlacement.php';
require_once __DIR__ . '/InstallerLifecycle.php';
require_once __DIR__ . '/InstallerApplication.php';

(new InstallerApplication(__DIR__, [
    'installer_path' => __FILE__,
    'allow_self_delete' => false,
]))->run();
