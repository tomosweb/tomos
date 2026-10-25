<?php

declare(strict_types=1);

// Keep the existing token-issuance security boundary and render it as a dedicated integration page.
$_GET['view'] = 'obsidian';
require dirname(__DIR__) . '/security/index.php';
