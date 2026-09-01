<?php

declare(strict_types=1);

namespace Tomos;

final class ContentSecurityPolicy
{
    public static function build(bool $hasAnalytics, string $nonce): string
    {
        $nonce = trim($nonce);
        if ($hasAnalytics && $nonce !== '') {
            $scriptSource = "https://www.googletagmanager.com 'nonce-" . $nonce . "'";
            $connectSource = "'self' https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com";
        } else {
            $scriptSource = "'none'";
            $connectSource = "'self'";
        }

        return "default-src 'self'; script-src " . $scriptSource
            . '; connect-src ' . $connectSource
            . "; style-src 'self'; img-src 'self' data: http: https:; frame-src https://www.youtube.com;"
            . " object-src 'none'; base-uri 'self'; frame-ancestors 'none'";
    }
}
