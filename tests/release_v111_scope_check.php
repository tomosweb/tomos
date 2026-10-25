<?php

declare(strict_types=1);

// The immutable v1.1.11 delta contract is intentionally not a gate for future releases.
// Its exact source/ZIP checks remain unchanged in tools/check-v1.1.11-scope.php.
$version = trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
if ($version !== '1.1.11') {
    echo "release_v111_scope_check: SKIP (historical v1.1.11-only scope; current {$version})\n";
    exit(0);
}
require_once dirname(__DIR__) . '/tools/check-v1.1.11-scope.php';
checkV111Scope(dirname(__DIR__));
