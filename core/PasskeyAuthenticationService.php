<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PasskeyAuthenticationService
{
    public const AUTHENTICATED_AT_KEY = 'tomos_passkey_authenticated_at';

    private PasskeyEnvironment $environment;
    private PasskeyCredentialStore $store;
    private PasskeyChallengeStore $challenges;
    private PasskeyWebAuthnClient $client;
    private int $now;

    public function __construct(
        PasskeyEnvironment $environment,
        PasskeyCredentialStore $store,
        PasskeyChallengeStore $challenges,
        PasskeyWebAuthnClient $client,
        ?int $now = null
    ) {
        $this->environment = $environment;
        $this->store = $store;
        $this->challenges = $challenges;
        $this->client = $client;
        $this->now = $now ?? time();
    }

    /**
     * @param array<string,mixed> $session
     * @return array{public_key:mixed}
     */
    public function begin(array &$session): array
    {
        if (!$this->environment->isAvailable()) {
            throw new RuntimeException('Passkey environment is not available.');
        }

        $rpId = $this->environment->rpId();
        $allowIds = [];
        foreach ($this->store->all() as $record) {
            if ((string) ($record['rp_id'] ?? '') !== $rpId) {
                continue;
            }
            $decoded = $this->base64UrlDecode((string) ($record['credential_id'] ?? ''));
            if ($decoded !== null) {
                $allowIds[] = $decoded;
            }
        }

        if ($allowIds === []) {
            throw new RuntimeException('Registered passkey is not available for this Tomos site.');
        }

        $options = $this->client->createAuthenticationOptions($rpId, $allowIds);
        $challenge = (string) ($options['challenge'] ?? '');
        if ($challenge === '' || !$this->challenges->remember($session, 'authenticate', $challenge, 120)) {
            throw new RuntimeException('Could not create authentication challenge.');
        }

        return ['public_key' => $options['public_key'] ?? null];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function complete(array &$session, array $payload): array
    {
        if (!$this->environment->isAvailable()) {
            throw new RuntimeException('Passkey environment is not available.');
        }

        $challenge = $this->challenges->consume($session, 'authenticate');
        if ($challenge === null) {
            throw new RuntimeException('Authentication challenge is missing or expired.');
        }

        $credentialId = trim((string) ($payload['credential_id'] ?? ''));
        if ($credentialId === '') {
            throw new RuntimeException('Credential ID is missing.');
        }

        $record = $this->store->load($credentialId);
        $rpId = $this->environment->rpId();
        if ($record === null || (string) ($record['rp_id'] ?? '') !== $rpId) {
            throw new RuntimeException('Registered passkey could not be found.');
        }

        $verified = $this->client->verifyAuthentication(
            $rpId,
            $this->environment->origin(),
            $payload,
            $challenge,
            (string) ($record['public_key'] ?? ''),
            (int) ($record['sign_count'] ?? 0)
        );

        $signCount = max(0, (int) ($verified['sign_count'] ?? (int) ($record['sign_count'] ?? 0)));
        if (!$this->store->updateUsage($credentialId, $signCount, $this->now)) {
            throw new RuntimeException('Could not update passkey usage information.');
        }

        $session['tomos_post_authenticated'] = true;
        $session[self::AUTHENTICATED_AT_KEY] = $this->now;

        return $this->store->load($credentialId) ?? $record;
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return null;
        }
        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }
}
