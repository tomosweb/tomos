<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

require_once __DIR__ . '/PostAuthPolicy.php';

final class PasskeyManagementService
{
    private PasskeyEnvironment $environment;
    private PasskeyCredentialStore $store;
    private array $config;

    public function __construct(PasskeyEnvironment $environment, PasskeyCredentialStore $store, array $config = [])
    {
        $this->environment = $environment;
        $this->store = $store;
        $this->config = $config;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $rpId = $this->environment->rpId();
        return array_values(array_filter($this->store->all(), static function (array $record) use ($rpId): bool {
            return (string) ($record['rp_id'] ?? '') === $rpId;
        }));
    }

    /** @return array<string,mixed> */
    public function rename(string $credentialId, string $label): array
    {
        return $this->store->withManagementLock(function () use ($credentialId, $label): array {
            return $this->renameUnlocked($credentialId, $label);
        });
    }

    private function renameUnlocked(string $credentialId, string $label): array
    {
        $record = $this->loadOwned($credentialId);
        $label = trim($label);
        if ($label === '' || strlen($label) > 100) {
            throw new RuntimeException('パスキーの名称は1〜100文字で入力してください。');
        }

        $record['label'] = $label;
        if (!$this->store->save($record)) {
            throw new RuntimeException('パスキーの名称を更新できませんでした。');
        }

        return $this->store->load($credentialId) ?? $record;
    }

    public function delete(string $credentialId): void
    {
        $this->store->withManagementLock(function () use ($credentialId): void {
            $this->loadOwned($credentialId);
            // Reload after acquiring the same lock used by login-mode changes.
            $config = $this->store->currentConfig($this->config);
            if (!PostAuthPolicy::allowsPassphrase($config) && count($this->all()) <= 1) {
                throw new RuntimeException('パスキー専用モードでは最後のパスキーを削除できません。');
            }
            if (!$this->store->delete($credentialId)) {
                throw new RuntimeException('パスキーを削除できませんでした。');
            }
        });
    }

    /** @return array<string,mixed> */
    private function loadOwned(string $credentialId): array
    {
        $record = $this->store->load(trim($credentialId));
        if ($record === null || (string) ($record['rp_id'] ?? '') !== $this->environment->rpId()) {
            throw new RuntimeException('対象のパスキーを確認できませんでした。');
        }
        return $record;
    }
}
