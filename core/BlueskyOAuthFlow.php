<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthStartResult
{
    public string $authorizationUrl;
    public string $state;

    public function __construct(string $authorizationUrl, string $state)
    {
        $this->authorizationUrl = $authorizationUrl;
        $this->state = $state;
    }
}

final class BlueskyOAuthFlow
{
    private array $config;
    private string $rootDir;
    private BlueskyOAuthHttpClient $http;
    private BlueskyIdentityResolver $identityResolver;
    private BlueskyOAuthDiscovery $discovery;
    private BlueskyOAuthStateStore $stateStore;
    private BlueskyAccountStore $accountStore;
    private BlueskyOAuthKeyStore $clientKeyStore;

    public function __construct(
        array $config,
        string $rootDir,
        ?BlueskyOAuthHttpClient $http = null,
        ?BlueskyIdentityResolver $identityResolver = null,
        ?BlueskyOAuthDiscovery $discovery = null
    ) {
        $this->config = $config;
        $this->rootDir = $rootDir;
        $this->http = $http ?? new BlueskyOAuthHttpClient();
        $this->identityResolver = $identityResolver ?? new BlueskyIdentityResolver($this->http);
        $this->discovery = $discovery ?? new BlueskyOAuthDiscovery($this->http);
        $this->stateStore = new BlueskyOAuthStateStore($rootDir);
        $this->accountStore = new BlueskyAccountStore($rootDir);
        $this->clientKeyStore = new BlueskyOAuthKeyStore($rootDir);
    }

    public function start(string $identifier): BlueskyOAuthStartResult
    {
        $identity = $this->identityResolver->resolve($identifier);
        $server = $this->discovery->discover($identity->pdsUrl);
        $clientKey = $this->clientKeyStore->loadOrCreate();
        if ($clientKey === null) {
            throw new \RuntimeException('Bluesky OAuth client key could not be loaded.');
        }

        $clientId = BlueskyOAuthMetadata::siteBaseUrl($this->config) . '/oauth-client-metadata.json.php';
        $redirectUri = BlueskyOAuthMetadata::siteBaseUrl($this->config) . '/post/social/bluesky/callback/';
        $state = bin2hex(random_bytes(32));
        $codeVerifier = Es256Jwt::base64Url(random_bytes(48));
        $codeChallenge = Es256Jwt::base64Url(hash('sha256', $codeVerifier, true));
        $dpopKey = BlueskyOAuthSessionKey::generate();

        $assertion = BlueskyClientAssertion::create($clientId, $server->issuer, $clientKey);
        $form = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => BlueskyOAuthMetadata::SCOPE,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'login_hint' => $identifier,
        ];

        [$parResponse, $dpopNonce] = $this->postFormWithDpop(
            $server->parEndpoint,
            $form,
            $dpopKey,
            null,
            null
        );
        if ($parResponse->status < 200 || $parResponse->status >= 300) {
            $detail = $this->safeOAuthErrorDetail($parResponse);
            throw new \RuntimeException(
                'Bluesky authorization request was rejected'
                . ($detail !== '' ? ': ' . $detail : '.')
            );
        }
        $par = $this->http->json($parResponse);
        $requestUri = (string) ($par['request_uri'] ?? '');
        if ($requestUri === '' || $dpopNonce === '') {
            throw new \RuntimeException('Bluesky authorization server returned an incomplete PAR response.');
        }

        $saved = $this->stateStore->save($state, [
            'expected_did' => $identity->did,
            'handle' => $identity->handle,
            'identifier' => $identifier,
            'pds_url' => $identity->pdsUrl,
            'issuer' => $server->issuer,
            'authorization_endpoint' => $server->authorizationEndpoint,
            'token_endpoint' => $server->tokenEndpoint,
            'par_endpoint' => $server->parEndpoint,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'scope' => BlueskyOAuthMetadata::SCOPE,
            'code_verifier' => $codeVerifier,
            'dpop_key' => $dpopKey,
            'auth_dpop_nonce' => $dpopNonce,
            'request_uri' => $requestUri,
        ]);
        if (!$saved) {
            throw new \RuntimeException('Bluesky OAuth state could not be saved.');
        }

        $authorizationUrl = $server->authorizationEndpoint
            . (strpos($server->authorizationEndpoint, '?') === false ? '?' : '&')
            . http_build_query([
                'client_id' => $clientId,
                'request_uri' => $requestUri,
            ], '', '&', PHP_QUERY_RFC3986);

        return new BlueskyOAuthStartResult($authorizationUrl, $state);
    }

    /** @return array<string,mixed> */
    public function complete(string $state, string $code, string $issuer): array
    {
        $record = $this->stateStore->load($state);
        if ($record === null) {
            throw new \RuntimeException('Bluesky OAuth session has expired or is invalid.');
        }

        try {
            $expectedIssuer = rtrim((string) ($record['issuer'] ?? ''), '/');
            if ($issuer === '' || !hash_equals($expectedIssuer, rtrim($issuer, '/'))) {
                throw new \RuntimeException('Bluesky OAuth issuer did not match the authorization request.');
            }
            if ($code === '') {
                throw new \RuntimeException('Bluesky OAuth callback did not include an authorization code.');
            }

            $clientKey = $this->clientKeyStore->loadOrCreate();
            if ($clientKey === null) {
                throw new \RuntimeException('Bluesky OAuth client key could not be loaded.');
            }
            $clientId = (string) ($record['client_id'] ?? '');
            $tokenEndpoint = (string) ($record['token_endpoint'] ?? '');
            $dpopKey = is_array($record['dpop_key'] ?? null) ? $record['dpop_key'] : [];
            $assertion = BlueskyClientAssertion::create($clientId, $expectedIssuer, $clientKey);

            $form = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => (string) ($record['redirect_uri'] ?? ''),
                'client_id' => $clientId,
                'code_verifier' => (string) ($record['code_verifier'] ?? ''),
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $assertion,
            ];

            [$tokenResponse, $authNonce] = $this->postFormWithDpop(
                $tokenEndpoint,
                $form,
                $dpopKey,
                (string) ($record['auth_dpop_nonce'] ?? ''),
                null
            );
            if ($tokenResponse->status < 200 || $tokenResponse->status >= 300) {
                throw new \RuntimeException('Bluesky token request was rejected.');
            }
            $token = $this->http->json($tokenResponse);

            $accessToken = (string) ($token['access_token'] ?? '');
            $refreshToken = (string) ($token['refresh_token'] ?? '');
            $tokenType = strtolower((string) ($token['token_type'] ?? ''));
            $subject = (string) ($token['sub'] ?? '');
            $scope = trim((string) ($token['scope'] ?? ''));
            $expectedDid = (string) ($record['expected_did'] ?? '');

            if ($accessToken === '' || $refreshToken === '' || $tokenType !== 'dpop') {
                throw new \RuntimeException('Bluesky token response was incomplete.');
            }
            if ($subject === '' || !hash_equals($expectedDid, $subject)) {
                throw new \RuntimeException('Bluesky account DID did not match the account that started authorization.');
            }
            $granted = preg_split('/\s+/', $scope) ?: [];
            if (!in_array('atproto', $granted, true)) {
                throw new \RuntimeException('Bluesky token response did not grant the required atproto scope.');
            }
            if (!in_array('repo:app.bsky.feed.post?action=create', $granted, true)) {
                throw new \RuntimeException('Bluesky did not grant permission to create posts.');
            }
            if ($authNonce === '') {
                throw new \RuntimeException('Bluesky token response did not include a DPoP nonce.');
            }

            $account = [
                'did' => $subject,
                'handle' => (string) ($record['handle'] ?? ''),
                'pds_url' => (string) ($record['pds_url'] ?? ''),
                'issuer' => $expectedIssuer,
                'token_endpoint' => $tokenEndpoint,
                'client_id' => $clientId,
                'scope' => $scope,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'dpop_key' => $dpopKey,
                'auth_dpop_nonce' => $authNonce,
                'resource_dpop_nonce' => '',
                'connected_at' => gmdate('c'),
            ];
            if (!$this->accountStore->save($account)) {
                throw new \RuntimeException('Bluesky account session could not be saved.');
            }

            return $account;
        } finally {
            $this->stateStore->delete($state);
        }
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $dpopKey
     * @return array{0:BlueskyOAuthHttpResponse,1:string}
     */
    private function postFormWithDpop(
        string $url,
        array $form,
        array $dpopKey,
        ?string $nonce,
        ?string $accessToken
    ): array {
        $privatePem = (string) ($dpopKey['private_pem'] ?? '');
        $publicJwk = is_array($dpopKey['public_jwk'] ?? null) ? $dpopKey['public_jwk'] : [];
        if ($privatePem === '' || $publicJwk === []) {
            throw new \RuntimeException('DPoP session key is unavailable.');
        }

        $attemptNonce = $nonce ?? '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = BlueskyDpop::proof(
                'POST',
                $url,
                $privatePem,
                $publicJwk,
                $attemptNonce !== '' ? $attemptNonce : null,
                $accessToken
            );
            $response = $this->http->postForm($url, $form, ['DPoP: ' . $proof]);
            $returnedNonce = $response->header('dpop-nonce');

            if ($returnedNonce !== '' && $response->status >= 200 && $response->status < 300) {
                return [$response, $returnedNonce];
            }
            if ($returnedNonce !== '' && $returnedNonce !== $attemptNonce && $attempt === 0) {
                $attemptNonce = $returnedNonce;
                continue;
            }
            if ($response->status >= 200 && $response->status < 300 && $returnedNonce === '') {
                throw new \RuntimeException('DPoP-protected response omitted the required nonce.');
            }
            return [$response, $returnedNonce];
        }

        throw new \RuntimeException('DPoP nonce retry failed.');
    }

    private function safeOAuthErrorDetail(BlueskyOAuthHttpResponse $response): string
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return 'HTTP ' . $response->status;
        }

        $parts = [];
        foreach (['error', 'error_description'] as $key) {
            $value = $decoded[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
            $parts[] = $key . '=' . mb_substr($value, 0, 300, 'UTF-8');
        }

        if ($parts === []) {
            return 'HTTP ' . $response->status;
        }

        return 'HTTP ' . $response->status . ' ' . implode(' ', $parts);
    }

}
