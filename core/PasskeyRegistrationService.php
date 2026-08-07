<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PasskeyRegistrationService
{
    public const AUTHORIZED_UNTIL_KEY = 'tomos_passkey_registration_authorized_until';

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

    /** @param array<string,mixed> $session */
    public function authorizeAfterPassphrase(array &$session, int $ttlSeconds = 300): void
    {
        $ttlSeconds = max(1, min(600, $ttlSeconds));
        $session[self::AUTHORIZED_UNTIL_KEY] = $this->now + $ttlSeconds;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{public_key:mixed}
     */
    public function begin(array &$session): array
    {
        $this->assertReady($session);
        $rpId = $this->environment->rpId();

        $excludeIds = [];
        foreach ($this->store->all() as $record) {
            if (($record['rp_id'] ?? '') !== $rpId) {
                continue;
            }
            $decoded = $this->base64UrlDecode((string) ($record['credential_id'] ?? ''));
            if ($decoded !== null) {
                $excludeIds[] = $decoded;
            }
        }

        $options = $this->client->createRegistrationOptions($rpId, $excludeIds);
        $challenge = (string) ($options['challenge'] ?? '');
        if ($challenge === '' || !$this->challenges->remember($session, 'register', $challenge, 120)) {
            throw new RuntimeException('Could not create registration challenge.');
        }

        return ['public_key' => $options['public_key'] ?? null];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function complete(array &$session, array $payload, string $label = ''): array
    {
        $this->assertReady($session);
        $challenge = $this->challenges->consume($session, 'register');
        if ($challenge === null) {
            throw new RuntimeException('Registration challenge is missing or expired.');
        }

        $rpId = $this->environment->rpId();
        $origin = $this->environment->origin();
        $verified = $this->client->verifyRegistration($rpId, $origin, $payload, $challenge);
        $credentialId = (string) ($verified['credential_id'] ?? '');
        if ($credentialId === '') {
            throw new RuntimeException('Credential ID is missing.');
        }
        if ($this->store->load($credentialId) !== null) {
            throw new RuntimeException('This passkey is already registered.');
        }

        $record = [
            'credential_id' => $credentialId,
            'public_key' => (string) ($verified['public_key'] ?? ''),
            'sign_count' => (int) ($verified['sign_count'] ?? 0),
            'transports' => is_array($verified['transports'] ?? null) ? $verified['transports'] : [],
            'label' => trim($label),
            'created_at' => $this->now,
            'last_used_at' => null,
            'rp_id' => $rpId,
        ];

        if (!$this->store->save($record)) {
            throw new RuntimeException('Could not persist passkey credential.');
        }

        return $this->store->load($credentialId) ?? $record;
    }

    /** @param array<string,mixed> $session */
    private function assertReady(array $session): void
    {
        if (!$this->environment->isAvailable()) {
            throw new RuntimeException('Passkey environment is not available.');
        }
        if ((int) ($session[self::AUTHORIZED_UNTIL_KEY] ?? 0) < $this->now) {
            throw new RuntimeException('Passphrase re-authentication is required before passkey registration.');
        }
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return null;
        }
        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        return is_string($decoded) ? $decoded : null;
    }
}
