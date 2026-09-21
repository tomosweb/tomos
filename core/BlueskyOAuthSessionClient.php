<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'Es256Jwt' => 'Es256Jwt.php',
    'BlueskyDpop' => 'BlueskyDpop.php',
    'BlueskyOAuthHttpClient' => 'BlueskyOAuthHttpClient.php',
    'BlueskyAccountStore' => 'BlueskyAccountStore.php',
    'BlueskyOAuthKeyStore' => 'BlueskyOAuthKeyStore.php',
    'BlueskyClientAssertion' => 'BlueskyClientAssertion.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class BlueskyOAuthSessionClient
{
    private array $config;
    private string $rootDir;
    private BlueskyOAuthHttpClient $http;
    private BlueskyAccountStore $accountStore;
    private BlueskyOAuthKeyStore $clientKeyStore;

    public function __construct(array $config, string $rootDir, ?BlueskyOAuthHttpClient $http = null)
    {
        $this->config = $config;
        $this->rootDir = $rootDir;
        $this->http = $http ?? new BlueskyOAuthHttpClient();
        $this->accountStore = new BlueskyAccountStore($rootDir);
        $this->clientKeyStore = new BlueskyOAuthKeyStore($rootDir);
    }

    public function isConnected(): bool
    {
        $account = $this->accountStore->load();
        return is_array($account)
            && (string) ($account['did'] ?? '') !== ''
            && (string) ($account['access_token'] ?? '') !== ''
            && (string) ($account['refresh_token'] ?? '') !== '';
    }

    /** @return array<string,mixed>|null */
    public function account(): ?array
    {
        return $this->accountStore->load();
    }

    public function disconnect(): bool
    {
        return $this->accountStore->delete();
    }

    public function getPublic(string $url, int $maxBytes = 262144): BlueskyOAuthHttpResponse
    {
        return $this->http->getWithLimit($url, $maxBytes, ['Accept: text/html,image/*;q=0.9,*/*;q=0.1']);
    }

    public function postJson(string $path, array $payload): BlueskyOAuthHttpResponse
    {
        $account = $this->accountStore->load();
        if ($account === null) {
            throw new \RuntimeException('Bluesky is not connected.');
        }

        $response = $this->authorizedPostJson($account, $path, $payload);
        if ($response->status !== 401) {
            return $response;
        }

        $account = $this->refreshSession((string) ($account['access_token'] ?? ''));
        return $this->authorizedPostJson($account, $path, $payload);
    }

    public function postBinary(string $path, string $body, string $contentType): BlueskyOAuthHttpResponse
    {
        $account = $this->accountStore->load();
        if ($account === null) {
            throw new \RuntimeException('Bluesky is not connected.');
        }

        $response = $this->authorizedPostBinary($account, $path, $body, $contentType);
        if ($response->status !== 401) {
            return $response;
        }

        $account = $this->refreshSession((string) ($account['access_token'] ?? ''));
        return $this->authorizedPostBinary($account, $path, $body, $contentType);
    }

    /** @param array<string,mixed> $account */
    private function authorizedPostJson(array $account, string $path, array $payload): BlueskyOAuthHttpResponse
    {
        $pds = rtrim((string) ($account['pds_url'] ?? ''), '/');
        $accessToken = (string) ($account['access_token'] ?? '');
        $dpopKey = is_array($account['dpop_key'] ?? null) ? $account['dpop_key'] : [];
        if ($pds === '' || $accessToken === '' || $dpopKey === []) {
            throw new \RuntimeException('Bluesky OAuth session is incomplete.');
        }

        $url = $pds . '/' . ltrim($path, '/');
        $nonce = (string) ($account['resource_dpop_nonce'] ?? '');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = BlueskyDpop::proof(
                'POST',
                $url,
                (string) ($dpopKey['private_pem'] ?? ''),
                is_array($dpopKey['public_jwk'] ?? null) ? $dpopKey['public_jwk'] : [],
                $nonce !== '' ? $nonce : null,
                $accessToken
            );
            $response = $this->http->postJson($url, $payload, [
                'Authorization: DPoP ' . $accessToken,
                'DPoP: ' . $proof,
                'Accept: application/json',
            ]);
            $returnedNonce = $response->header('dpop-nonce');

            if ($returnedNonce !== '' && $returnedNonce !== $nonce) {
                $this->saveResourceNonce($accessToken, $returnedNonce);
            }

            if (($response->status === 400 || $response->status === 401)
                && $returnedNonce !== ''
                && $returnedNonce !== $nonce
                && $attempt === 0
            ) {
                $nonce = $returnedNonce;
                continue;
            }

            if ($response->status >= 200 && $response->status < 300 && $returnedNonce === '') {
                throw new \RuntimeException('Bluesky PDS omitted the required DPoP nonce.');
            }
            return $response;
        }

        throw new \RuntimeException('Bluesky DPoP retry failed.');
    }

    /** @param array<string,mixed> $account */
    private function authorizedPostBinary(array $account, string $path, string $body, string $contentType): BlueskyOAuthHttpResponse
    {
        $pds = rtrim((string) ($account['pds_url'] ?? ''), '/');
        $accessToken = (string) ($account['access_token'] ?? '');
        $dpopKey = is_array($account['dpop_key'] ?? null) ? $account['dpop_key'] : [];
        if ($pds === '' || $accessToken === '' || $dpopKey === []) {
            throw new \RuntimeException('Bluesky OAuth session is incomplete.');
        }

        $url = $pds . '/' . ltrim($path, '/');
        $nonce = (string) ($account['resource_dpop_nonce'] ?? '');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = BlueskyDpop::proof(
                'POST',
                $url,
                (string) ($dpopKey['private_pem'] ?? ''),
                is_array($dpopKey['public_jwk'] ?? null) ? $dpopKey['public_jwk'] : [],
                $nonce !== '' ? $nonce : null,
                $accessToken
            );
            $response = $this->http->postBinary($url, $body, $contentType, [
                'Authorization: DPoP ' . $accessToken,
                'DPoP: ' . $proof,
                'Accept: application/json',
            ]);
            $returnedNonce = $response->header('dpop-nonce');

            if ($returnedNonce !== '' && $returnedNonce !== $nonce) {
                $this->saveResourceNonce($accessToken, $returnedNonce);
            }

            if (($response->status === 400 || $response->status === 401)
                && $returnedNonce !== ''
                && $returnedNonce !== $nonce
                && $attempt === 0
            ) {
                $nonce = $returnedNonce;
                continue;
            }

            if ($response->status >= 200 && $response->status < 300 && $returnedNonce === '') {
                throw new \RuntimeException('Bluesky PDS omitted the required DPoP nonce.');
            }
            return $response;
        }

        throw new \RuntimeException('Bluesky DPoP retry failed.');
    }

    /** @return array<string,mixed> */
    private function refreshSession(string $staleAccessToken): array
    {
        return $this->accountStore->updateLocked(function (?array $current) use ($staleAccessToken): array {
            if ($current === null) {
                throw new \RuntimeException('Bluesky is not connected.');
            }

            $currentAccessToken = (string) ($current['access_token'] ?? '');
            if ($staleAccessToken !== '' && $currentAccessToken !== '' && !hash_equals($staleAccessToken, $currentAccessToken)) {
                return $current;
            }

            $clientId = (string) ($current['client_id'] ?? '');
            $issuer = (string) ($current['issuer'] ?? '');
            $tokenEndpoint = (string) ($current['token_endpoint'] ?? '');
            $refreshToken = (string) ($current['refresh_token'] ?? '');
            $dpopKey = is_array($current['dpop_key'] ?? null) ? $current['dpop_key'] : [];
            if ($clientId === '' || $issuer === '' || $tokenEndpoint === '' || $refreshToken === '' || $dpopKey === []) {
                throw new \RuntimeException('Bluesky refresh session is incomplete.');
            }

            $clientKey = $this->clientKeyStore->loadOrCreate();
            if ($clientKey === null) {
                throw new \RuntimeException('Bluesky OAuth client key is unavailable.');
            }
            $assertion = BlueskyClientAssertion::create($clientId, $issuer, $clientKey);

            $form = [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $assertion,
            ];

            $nonce = (string) ($current['auth_dpop_nonce'] ?? '');
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $proof = BlueskyDpop::proof(
                    'POST',
                    $tokenEndpoint,
                    (string) ($dpopKey['private_pem'] ?? ''),
                    is_array($dpopKey['public_jwk'] ?? null) ? $dpopKey['public_jwk'] : [],
                    $nonce !== '' ? $nonce : null,
                    null
                );
                $response = $this->http->postForm($tokenEndpoint, $form, [
                    'DPoP: ' . $proof,
                    'Accept: application/json',
                ]);
                $returnedNonce = $response->header('dpop-nonce');

                if (($response->status === 400 || $response->status === 401)
                    && $returnedNonce !== ''
                    && $returnedNonce !== $nonce
                    && $attempt === 0
                ) {
                    $nonce = $returnedNonce;
                    continue;
                }
                if ($response->status < 200 || $response->status >= 300) {
                    throw new \RuntimeException('Bluesky token refresh was rejected.');
                }
                if ($returnedNonce === '') {
                    throw new \RuntimeException('Bluesky token refresh omitted the required DPoP nonce.');
                }

                $token = $this->http->json($response);
                $accessToken = (string) ($token['access_token'] ?? '');
                $nextRefresh = (string) ($token['refresh_token'] ?? '');
                $scope = trim((string) ($token['scope'] ?? ''));
                $subject = (string) ($token['sub'] ?? '');
                if ($accessToken === '' || $nextRefresh === '' || strtolower((string) ($token['token_type'] ?? '')) !== 'dpop') {
                    throw new \RuntimeException('Bluesky refresh response was incomplete.');
                }
                if ($subject !== '' && $subject !== (string) ($current['did'] ?? '')) {
                    throw new \RuntimeException('Bluesky refresh response account DID changed unexpectedly.');
                }
                if ($scope === '') {
                    throw new \RuntimeException('Bluesky refresh response omitted granted scopes.');
                }
                $granted = preg_split('/\s+/', $scope) ?: [];
                if (!in_array('atproto', $granted, true)
                    || !in_array('repo:app.bsky.feed.post?action=create', $granted, true)
                ) {
                    throw new \RuntimeException('Bluesky refresh response no longer grants post creation.');
                }
                $current['scope'] = $scope;

                $current['access_token'] = $accessToken;
                $current['refresh_token'] = $nextRefresh;
                $current['auth_dpop_nonce'] = $returnedNonce;
                $current['refreshed_at'] = gmdate('c');
                return $current;
            }

            throw new \RuntimeException('Bluesky token refresh retry failed.');
        });
    }

    private function saveResourceNonce(string $accessToken, string $nonce): void
    {
        try {
            $this->accountStore->updateLocked(function (?array $current) use ($accessToken, $nonce): array {
                if ($current === null) {
                    throw new \RuntimeException('Bluesky account session is unavailable.');
                }
                if ((string) ($current['access_token'] ?? '') === $accessToken) {
                    $current['resource_dpop_nonce'] = $nonce;
                }
                return $current;
            });
        } catch (\Throwable $exception) {
            // A nonce persistence failure must not mutate or invalidate the article publication.
        }
    }

}
