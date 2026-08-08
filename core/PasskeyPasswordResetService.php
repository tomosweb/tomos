<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PasskeyPasswordResetService
{
    public const AUTHORIZED_UNTIL_KEY = 'tomos_passkey_password_reset_authorized_until';

    private PasskeyEnvironment $environment;
    private PasskeyCredentialStore $store;
    private PasskeyChallengeStore $challenges;
    private PasskeyWebAuthnClient $client;
    private PostPasswordHashUpdater $updater;
    private int $now;

    public function __construct(
        PasskeyEnvironment $environment,
        PasskeyCredentialStore $store,
        PasskeyChallengeStore $challenges,
        PasskeyWebAuthnClient $client,
        PostPasswordHashUpdater $updater,
        ?int $now = null
    ) {
        $this->environment = $environment;
        $this->store = $store;
        $this->challenges = $challenges;
        $this->client = $client;
        $this->updater = $updater;
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
        if ($challenge === '' || !$this->challenges->remember($session, 'password-reset', $challenge, 120)) {
            throw new RuntimeException('Could not create password reset challenge.');
        }

        unset($session[self::AUTHORIZED_UNTIL_KEY]);
        return ['public_key' => $options['public_key'] ?? null];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function completeReauthentication(array &$session, array $payload, int $ttlSeconds = 300): array
    {
        if (!$this->environment->isAvailable()) {
            throw new RuntimeException('Passkey environment is not available.');
        }

        $challenge = $this->challenges->consume($session, 'password-reset');
        if ($challenge === null) {
            throw new RuntimeException('Password reset challenge is missing or expired.');
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

        $ttlSeconds = max(1, min(600, $ttlSeconds));
        $session[self::AUTHORIZED_UNTIL_KEY] = $this->now + $ttlSeconds;
        $session[PasskeyAuthenticationService::AUTHENTICATED_AT_KEY] = $this->now;

        return $this->store->load($credentialId) ?? $record;
    }

    /** @param array<string,mixed> $session */
    public function resetPassphrase(array &$session, string $newPassphrase, string $confirmation): void
    {
        if ((int) ($session[self::AUTHORIZED_UNTIL_KEY] ?? 0) < $this->now) {
            throw new RuntimeException('管理用合言葉を再設定するには、もう一度パスキーで認証してください。');
        }

        if ($newPassphrase === '' || strlen($newPassphrase) > 4096) {
            throw new RuntimeException('新しい管理用合言葉を入力してください。');
        }
        if (!hash_equals($newPassphrase, $confirmation)) {
            throw new RuntimeException('管理用合言葉の確認入力が一致しません。');
        }

        $hash = password_hash($newPassphrase, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('管理用合言葉のハッシュを作成できませんでした。');
        }

        $this->updater->update($hash);

        unset(
            $session[self::AUTHORIZED_UNTIL_KEY],
            $session[PasskeyAuthenticationService::AUTHENTICATED_AT_KEY],
            $session[PasskeyRegistrationService::AUTHORIZED_UNTIL_KEY]
        );
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
