<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PasskeyServerRecoveryService
{
    public const CHALLENGE_KEY = 'tomos_passkey_server_recovery_challenge';
    public const CHALLENGE_EXPIRES_AT_KEY = 'tomos_passkey_server_recovery_expires_at';
    public const FILE_NAME_KEY = 'tomos_passkey_server_recovery_file_name';
    public const REGISTRATION_AUTHORIZED_UNTIL_KEY = 'tomos_passkey_server_recovery_registration_authorized_until';

    private PasskeyEnvironment $environment;
    private PasskeyCredentialStore $store;
    private PasskeyChallengeStore $challenges;
    private PasskeyWebAuthnClient $client;
    private string $rootDir;
    private int $now;

    public function __construct(
        PasskeyEnvironment $environment,
        PasskeyCredentialStore $store,
        PasskeyChallengeStore $challenges,
        PasskeyWebAuthnClient $client,
        string $rootDir,
        ?int $now = null
    ) {
        $this->environment = $environment;
        $this->store = $store;
        $this->challenges = $challenges;
        $this->client = $client;
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->now = $now ?? time();
    }

    /** @param array<string,mixed> $session */
    public function issueChallenge(array &$session, int $ttlSeconds = 600): string
    {
        $this->assertRecoveryEligible();
        $ttlSeconds = max(60, min(1800, $ttlSeconds));
        $challenge = bin2hex(random_bytes(32));
        $fileName = 'tomos-recovery-' . bin2hex(random_bytes(8)) . '.txt';
        $session[self::CHALLENGE_KEY] = $challenge;
        $session[self::CHALLENGE_EXPIRES_AT_KEY] = $this->now + $ttlSeconds;
        $session[self::FILE_NAME_KEY] = $fileName;
        unset($session[self::REGISTRATION_AUTHORIZED_UNTIL_KEY]);
        return $challenge;
    }

    /** @param array<string,mixed> $session */
    public function recoveryFileName(array $session): string
    {
        $fileName = (string) ($session[self::FILE_NAME_KEY] ?? '');
        if (preg_match('/\Atomos-recovery-[a-f0-9]{16}\.txt\z/', $fileName) !== 1) {
            throw new RuntimeException('復旧ファイル情報を確認できません。もう一度発行してください。');
        }
        return $fileName;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{name:string,contents:string,expires_at:int}
     */
    public function recoveryDownloadData(array $session): array
    {
        $this->assertRecoveryEligible();
        $challenge = (string) ($session[self::CHALLENGE_KEY] ?? '');
        $expiresAt = (int) ($session[self::CHALLENGE_EXPIRES_AT_KEY] ?? 0);
        if ($challenge === '' || $expiresAt < $this->now) {
            throw new RuntimeException('復旧コードの有効期限が切れています。もう一度発行してください。');
        }

        return [
            'name' => $this->recoveryFileName($session),
            'contents' => $challenge . "\n",
            'expires_at' => $expiresAt,
        ];
    }

    /** @param array<string,mixed> $session */
    public function verifyServerAccess(array &$session, int $registrationTtlSeconds = 300): void
    {
        $this->assertRecoveryEligible();

        $challenge = (string) ($session[self::CHALLENGE_KEY] ?? '');
        $expiresAt = (int) ($session[self::CHALLENGE_EXPIRES_AT_KEY] ?? 0);
        if ($challenge === '' || $expiresAt < $this->now) {
            $this->clearChallenge($session);
            throw new RuntimeException('復旧コードの有効期限が切れています。もう一度発行してください。');
        }

        $fileName = $this->recoveryFileName($session);
        $path = $this->rootDir . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($fileName . ' を確認できません。config.php と同じフォルダへ配置してください。');
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > 512) {
            throw new RuntimeException($fileName . ' の内容を確認できません。');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException($fileName . ' を読み込めませんでした。');
        }

        $actual = trim($contents);
        if ($actual === '' || !hash_equals($challenge, $actual)) {
            throw new RuntimeException($fileName . ' の復旧コードが一致しません。');
        }

        if (!@unlink($path)) {
            throw new RuntimeException($fileName . ' を削除できないため、復旧を続行できません。');
        }

        $this->clearChallenge($session);
        $registrationTtlSeconds = max(60, min(600, $registrationTtlSeconds));
        $session[self::REGISTRATION_AUTHORIZED_UNTIL_KEY] = $this->now + $registrationTtlSeconds;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{public_key:mixed}
     */
    public function beginRegistration(array &$session): array
    {
        $this->assertRegistrationAuthorized($session);
        $options = $this->client->createRegistrationOptions($this->environment->rpId(), []);
        $challenge = (string) ($options['challenge'] ?? '');
        if ($challenge === '' || !$this->challenges->remember($session, 'server-recovery-register', $challenge, 120)) {
            throw new RuntimeException('復旧用パスキー登録の準備に失敗しました。');
        }
        return ['public_key' => $options['public_key'] ?? null];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function completeRegistration(array &$session, array $payload, string $label = ''): array
    {
        $this->assertRegistrationAuthorized($session);
        $challenge = $this->challenges->consume($session, 'server-recovery-register');
        if ($challenge === null) {
            throw new RuntimeException('復旧用パスキー登録の有効期限が切れています。');
        }

        $rpId = $this->environment->rpId();
        $verified = $this->client->verifyRegistration(
            $rpId,
            $this->environment->origin(),
            $payload,
            $challenge
        );

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
            throw new RuntimeException('復旧用パスキーを保存できませんでした。');
        }

        unset($session[self::REGISTRATION_AUTHORIZED_UNTIL_KEY]);
        return $this->store->load($credentialId) ?? $record;
    }

    /** @param array<string,mixed> $session */
    public function isRegistrationAuthorized(array $session): bool
    {
        return (int) ($session[self::REGISTRATION_AUTHORIZED_UNTIL_KEY] ?? 0) >= $this->now;
    }

    /** @param array<string,mixed> $session */
    private function assertRegistrationAuthorized(array $session): void
    {
        $this->assertRecoveryEligible();
        if (!$this->isRegistrationAuthorized($session)) {
            throw new RuntimeException('サーバー所有確認をもう一度行ってください。');
        }
    }

    private function assertRecoveryEligible(): void
    {
        if (!$this->environment->isAvailable()) {
            throw new RuntimeException('この環境ではパスキーを利用できません。');
        }

        $rpId = $this->environment->rpId();
        foreach ($this->store->all() as $record) {
            if ((string) ($record['rp_id'] ?? '') === $rpId) {
                throw new RuntimeException('登録済みパスキーがあります。通常のパスキー認証で合言葉を再設定してください。');
            }
        }
    }

    /** @param array<string,mixed> $session */
    private function clearChallenge(array &$session): void
    {
        unset(
            $session[self::CHALLENGE_KEY],
            $session[self::CHALLENGE_EXPIRES_AT_KEY],
            $session[self::FILE_NAME_KEY]
        );
    }
}
