<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthMetadata
{
    public const SCOPE = 'atproto repo:app.bsky.feed.post?action=create blob:*/*';

    /** @return array<string,mixed> */
    public static function clientMetadata(array $config): array
    {
        $base = self::siteBaseUrl($config);
        return [
            'client_id' => $base . '/oauth-client-metadata.json.php',
            'client_name' => 'Tomos',
            'client_uri' => $base . '/',
            'redirect_uris' => [$base . '/post/social/bluesky/callback/'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => self::SCOPE,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'ES256',
            'jwks_uri' => $base . '/tomos-bluesky-jwks.json.php',
            'application_type' => 'web',
            'dpop_bound_access_tokens' => true,
        ];
    }

    public static function siteBaseUrl(array $config): string
    {
        $site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $url = rtrim((string) ($site['url'] ?? ''), '/');
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('Bluesky OAuth requires site.url to be a public HTTPS site URL.');
        }

        $publicBasePath = trim((string) (($site['public_base_path'] ?? '') ?: ($site['base_path'] ?? '')), '/');
        if ($publicBasePath !== '' && substr($url, -strlen('/' . $publicBasePath)) !== '/' . $publicBasePath) {
            $url .= '/' . $publicBasePath;
        }
        return $url;
    }
}
