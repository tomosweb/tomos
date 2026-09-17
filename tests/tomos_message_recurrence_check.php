<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/TomosMessageRecurrence.php';

use Tomos\TomosMessageRecurrence;

$now = 1700000000;
$failures = [];

function checkRecurrence(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

checkRecurrence(TomosMessageRecurrence::isEligible(null, $now), 'missing cookie is eligible');
checkRecurrence(!TomosMessageRecurrence::isEligible((string) $now, $now), 'current timestamp is suppressed');
checkRecurrence(!TomosMessageRecurrence::isEligible((string) ($now - 1), $now), 'inside interval is suppressed');
checkRecurrence(TomosMessageRecurrence::isEligible((string) ($now - TomosMessageRecurrence::INTERVAL_SECONDS), $now), 'exactly six hours is eligible');
checkRecurrence(TomosMessageRecurrence::isEligible((string) ($now - TomosMessageRecurrence::INTERVAL_SECONDS - 1), $now), 'after six hours is eligible');
foreach (['', 'abc', '-1', '0', '00000000000', '1700000000.0', '9999999999'] as $invalid) {
    checkRecurrence(TomosMessageRecurrence::isEligible($invalid, $now), 'invalid or future timestamp is safely eligible: ' . var_export($invalid, true));
}

$rootConfig = ['site' => ['url' => 'http://example.test', 'base_path' => '', 'public_base_path' => '']];
$subdirectoryConfig = ['site' => ['url' => 'https://example.test/tomos-a', 'base_path' => '/tomos-a', 'public_base_path' => '/tomos-a']];
$otherSubdirectoryConfig = ['site' => ['url' => 'https://example.test/tomos-b', 'base_path' => '/tomos-b', 'public_base_path' => '/tomos-b']];

checkRecurrence(TomosMessageRecurrence::cookiePath($rootConfig) === '/post/', 'root install cookie path is Post-scoped');
checkRecurrence(TomosMessageRecurrence::cookiePath($subdirectoryConfig) === '/tomos-a/post/', 'subdirectory install cookie path is isolated');
checkRecurrence(TomosMessageRecurrence::cookiePath($subdirectoryConfig) !== TomosMessageRecurrence::cookiePath($otherSubdirectoryConfig), 'two same-host installs do not share cookie path');

$httpOptions = TomosMessageRecurrence::cookieOptions($rootConfig, [], $now);
checkRecurrence($httpOptions['expires'] === $now + 21600, 'cookie expires at the recurrence boundary');
checkRecurrence($httpOptions['path'] === '/post/', 'cookie options retain root Post path');
checkRecurrence($httpOptions['secure'] === false, 'HTTP cookie is not marked Secure');
checkRecurrence($httpOptions['httponly'] === true, 'cookie is HttpOnly');
checkRecurrence($httpOptions['samesite'] === 'Lax', 'cookie is SameSite Lax');

$httpsOptions = TomosMessageRecurrence::cookieOptions($subdirectoryConfig, [], $now);
checkRecurrence($httpsOptions['secure'] === true, 'HTTPS site cookie is Secure');
checkRecurrence($httpsOptions['path'] === '/tomos-a/post/', 'HTTPS subdirectory cookie remains isolated');
checkRecurrence(TomosMessageRecurrence::cookieOptions($rootConfig, ['HTTPS' => 'on'], $now)['secure'] === true, 'HTTPS request marks cookie Secure');

if ($failures !== []) {
    fwrite(STDERR, "tomos_message_recurrence_check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "tomos_message_recurrence_check: PASS\n";
