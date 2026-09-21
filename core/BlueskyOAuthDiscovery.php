<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthServer
{
    public string $issuer;
    public string $authorizationEndpoint;
    public string $tokenEndpoint;
    public string $parEndpoint;
    public string $pdsUrl;

    public function __construct(
        string $issuer,
        string $authorizationEndpoint,
        string $tokenEndpoint,
        string $parEndpoint,
        string $pdsUrl
    ) {
        $this->issuer = $issuer;
        $this->authorizationEndpoint = $authorizationEndpoint;
        $this->tokenEndpoint = $tokenEndpoint;
        $this->parEndpoint = $parEndpoint;
        $this->pdsUrl = rtrim($pdsUrl, '/');
    }
}

final class BlueskyOAuthDiscovery
{
    private BlueskyOAuthHttpClient $http;

    public function __construct(?BlueskyOAuthHttpClient $http = null)
    {
        $this->http = $http ?? new BlueskyOAuthHttpClient();
    }

    public function discover(string $pdsUrl): BlueskyOAuthServer
    {
        $pdsUrl = rtrim($pdsUrl, '/');
        $resourceResponse = $this->http->get(
            $pdsUrl . '/.well-known/oauth-protected-resource',
            ['Accept: application/json']
        );
        if ($resourceResponse->status !== 200) {
            throw new \RuntimeException('PDS OAuth metadata could not be loaded.');
        }
        $resource = $this->http->json($resourceResponse);
        $servers = $resource['authorization_servers'] ?? null;
        if (!is_array($servers) || count($servers) !== 1 || !is_string($servers[0])) {
            throw new \RuntimeException('PDS OAuth metadata is invalid.');
        }
        $issuer = rtrim($servers[0], '/');
        if (!$this->validOrigin($issuer)) {
            throw new \RuntimeException('Authorization Server issuer is invalid.');
        }

        $authResponse = $this->http->get(
            $issuer . '/.well-known/oauth-authorization-server',
            ['Accept: application/json']
        );
        if ($authResponse->status !== 200) {
            throw new \RuntimeException('Authorization Server metadata could not be loaded.');
        }
        $metadata = $this->http->json($authResponse);
        if (rtrim((string) ($metadata['issuer'] ?? ''), '/') !== $issuer) {
            throw new \RuntimeException('Authorization Server issuer did not match.');
        }
        if (($metadata['client_id_metadata_document_supported'] ?? false) !== true) {
            throw new \RuntimeException('Authorization Server does not support client metadata documents.');
        }
        if (($metadata['authorization_response_iss_parameter_supported'] ?? false) !== true) {
            throw new \RuntimeException('Authorization Server does not support issuer-bound responses.');
        }
        if (($metadata['require_pushed_authorization_requests'] ?? false) !== true) {
            throw new \RuntimeException('Authorization Server does not require PAR.');
        }
        $responseTypes = $metadata['response_types_supported'] ?? [];
        if (!is_array($responseTypes) || !in_array('code', $responseTypes, true)) {
            throw new \RuntimeException('Authorization Server does not support authorization code responses.');
        }
        $grantTypes = $metadata['grant_types_supported'] ?? [];
        if (!is_array($grantTypes)
            || !in_array('authorization_code', $grantTypes, true)
            || !in_array('refresh_token', $grantTypes, true)
        ) {
            throw new \RuntimeException('Authorization Server does not support the required grant types.');
        }
        $pkce = $metadata['code_challenge_methods_supported'] ?? [];
        if (!is_array($pkce) || !in_array('S256', $pkce, true)) {
            throw new \RuntimeException('Authorization Server does not support S256 PKCE.');
        }
        $authMethods = $metadata['token_endpoint_auth_methods_supported'] ?? [];
        if (!is_array($authMethods) || !in_array('private_key_jwt', $authMethods, true)) {
            throw new \RuntimeException('Authorization Server does not support private_key_jwt.');
        }
        $authAlgs = $metadata['token_endpoint_auth_signing_alg_values_supported'] ?? [];
        if (!is_array($authAlgs) || !in_array('ES256', $authAlgs, true) || in_array('none', $authAlgs, true)) {
            throw new \RuntimeException('Authorization Server does not support ES256 client authentication.');
        }
        $algs = $metadata['dpop_signing_alg_values_supported'] ?? [];
        if (!is_array($algs) || !in_array('ES256', $algs, true)) {
            throw new \RuntimeException('Authorization Server does not support ES256 DPoP.');
        }
        $scopes = $metadata['scopes_supported'] ?? [];
        if (is_array($scopes) && $scopes !== [] && !in_array('atproto', $scopes, true)) {
            throw new \RuntimeException('Authorization Server does not advertise the atproto scope.');
        }

        $authorization = (string) ($metadata['authorization_endpoint'] ?? '');
        $token = (string) ($metadata['token_endpoint'] ?? '');
        $par = (string) ($metadata['pushed_authorization_request_endpoint'] ?? '');
        foreach ([$authorization, $token, $par] as $endpoint) {
            if (!$this->validHttpsUrl($endpoint)) {
                throw new \RuntimeException('Authorization Server endpoint is invalid.');
            }
        }

        return new BlueskyOAuthServer($issuer, $authorization, $token, $par, $pdsUrl);
    }

    private function validOrigin(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ((string) ($parts['path'] ?? '') === '' || (string) ($parts['path'] ?? '') === '/')
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    private function validHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
