<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ContentSecurityPolicy.php';

use Tomos\ContentSecurityPolicy;

function checkCsp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scriptDirective(string $policy): string
{
    if (preg_match('/(?:^|;)\\s*script-src\\s+([^;]+)/', $policy, $matches) !== 1) {
        return '';
    }

    return trim((string) $matches[1]);
}

$default = ContentSecurityPolicy::build(false, '');
$defaultScripts = scriptDirective($default);
checkCsp($defaultScripts === "'none'", 'default CSP must disable scripts');
checkCsp(strpos($defaultScripts, "'self'") === false, 'default CSP must not allow local scripts');
checkCsp(strpos($default, "'unsafe-inline'") === false, 'CSP must not allow unsafe inline scripts');

$analytics = ContentSecurityPolicy::build(true, 'nonce-test-123');
$analyticsScripts = scriptDirective($analytics);
checkCsp(strpos($analyticsScripts, 'https://www.googletagmanager.com') !== false, 'GTM must remain allowed for configured analytics');
checkCsp(strpos($analyticsScripts, "'nonce-nonce-test-123'") !== false, 'analytics nonce must be present');
checkCsp(strpos($analyticsScripts, "'self'") === false, 'analytics CSP must not grant all local scripts');
checkCsp(strpos($analytics, 'google-analytics.com') !== false, 'analytics connect sources must remain allowed');

$missingNonce = ContentSecurityPolicy::build(true, '');
checkCsp(scriptDirective($missingNonce) === "'none'", 'analytics without nonce must fail closed');

$configSample = (string) file_get_contents(dirname(__DIR__) . '/config.sample.php');
checkCsp(strpos($configSample, 'allow_theme_scripts') === false, 'global allow_theme_scripts setting must not return');

echo "security_csp_check: OK\n";
