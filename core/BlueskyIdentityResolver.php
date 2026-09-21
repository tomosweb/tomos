<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyIdentity
{
    public string $did;
    public string $handle;
    public string $pdsUrl;

    public function __construct(string $did, string $handle, string $pdsUrl)
    {
        $this->did = $did;
        $this->handle = $handle;
        $this->pdsUrl = rtrim($pdsUrl, '/');
    }
}

final class BlueskyIdentityResolver
{
    private BlueskyOAuthHttpClient $http;

    public function __construct(?BlueskyOAuthHttpClient $http = null)
    {
        $this->http = $http ?? new BlueskyOAuthHttpClient();
    }

    public function resolve(string $identifier): BlueskyIdentity
    {
        $identifier = trim($identifier);
        if (strpos($identifier, '@') === 0) {
            $identifier = substr($identifier, 1);
        }
        if ($identifier === '') {
            throw new \InvalidArgumentException('BlueskyのハンドルまたはDIDを入力してください。');
        }

        $handle = '';
        if (strpos($identifier, 'did:') === 0) {
            $did = $identifier;
        } else {
            $handle = strtolower(rtrim($identifier, '.'));
            if (!$this->validHandle($handle)) {
                throw new \InvalidArgumentException('Blueskyのハンドルを確認してください。');
            }
            $did = $this->resolveHandle($handle);
        }

        $document = $this->resolveDidDocument($did);
        if ((string) ($document['id'] ?? '') !== $did) {
            throw new \RuntimeException('DID document did not match the expected DID.');
        }

        $claimedHandle = $this->claimedHandle($document);
        if ($handle !== '') {
            if ($claimedHandle !== $handle) {
                throw new \RuntimeException('Bluesky handle did not verify bidirectionally.');
            }
        } elseif ($claimedHandle !== '') {
            $handle = $claimedHandle;
        }

        $pds = $this->pdsEndpoint($did, $document);
        return new BlueskyIdentity($did, $handle, $pds);
    }

    private function resolveHandle(string $handle): string
    {
        foreach (@dns_get_record('_atproto.' . $handle, DNS_TXT) ?: [] as $record) {
            $txt = (string) ($record['txt'] ?? '');
            if (strpos($txt, 'did=') === 0) {
                $did = substr($txt, 4);
                if ($this->supportedDid($did)) {
                    return $did;
                }
            }
        }

        $response = $this->http->get('https://' . $handle . '/.well-known/atproto-did', [
            'Accept: text/plain',
        ]);
        if ($response->status !== 200) {
            throw new \RuntimeException('Bluesky handle could not be resolved.');
        }
        $did = trim($response->body);
        if (!$this->supportedDid($did)) {
            throw new \RuntimeException('Bluesky handle returned an unsupported DID.');
        }
        return $did;
    }

    /** @return array<string,mixed> */
    private function resolveDidDocument(string $did): array
    {
        if (strpos($did, 'did:plc:') === 0) {
            $url = 'https://plc.directory/' . rawurlencode($did);
        } elseif (strpos($did, 'did:web:') === 0) {
            $host = substr($did, strlen('did:web:'));
            if ($host === '' || strpos($host, ':') !== false || strpos($host, '/') !== false) {
                throw new \RuntimeException('Unsupported did:web identifier.');
            }
            $url = 'https://' . $host . '/.well-known/did.json';
        } else {
            throw new \RuntimeException('Unsupported DID method.');
        }

        $response = $this->http->get($url, ['Accept: application/did+ld+json, application/json']);
        if ($response->status !== 200) {
            throw new \RuntimeException('DID document could not be resolved.');
        }
        return $this->http->json($response);
    }

    private function claimedHandle(array $document): string
    {
        foreach ($document['alsoKnownAs'] ?? [] as $value) {
            if (!is_string($value) || strpos($value, 'at://') !== 0) {
                continue;
            }
            $handle = substr($value, 5);
            if ($this->validHandle($handle)) {
                return strtolower($handle);
            }
        }
        return '';
    }

    private function pdsEndpoint(string $did, array $document): string
    {
        foreach ($document['service'] ?? [] as $service) {
            if (!is_array($service)) {
                continue;
            }
            $id = (string) ($service['id'] ?? '');
            $type = (string) ($service['type'] ?? '');
            $endpoint = (string) ($service['serviceEndpoint'] ?? '');
            if (($id === '#atproto_pds' || $id === $did . '#atproto_pds')
                && $type === 'AtprotoPersonalDataServer'
                && $this->validOrigin($endpoint)
            ) {
                return rtrim($endpoint, '/');
            }
        }
        throw new \RuntimeException('DID document does not contain an atproto PDS service.');
    }

    private function validOrigin(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && !isset($parts['user'], $parts['pass'])
            && ((string) ($parts['path'] ?? '') === '' || (string) ($parts['path'] ?? '') === '/')
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    private function supportedDid(string $did): bool
    {
        return preg_match('/^did:(?:plc|web):[A-Za-z0-9._:%-]*[A-Za-z0-9._-]$/', $did) === 1;
    }

    private function validHandle(string $handle): bool
    {
        return strlen($handle) <= 253
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $handle) === 1;
    }
}
