<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PasskeyManagementService
{
    private PasskeyEnvironment $environment;
    private PasskeyCredentialStore $store;
    private bool $protectLastCredential;

    public function __construct(PasskeyEnvironment $environment, PasskeyCredentialStore $store, array $config = [])
    {
        $this->environment = $environment;
        $this->store = $store;
        $this->protectLastCredential = (string) ($config['security']['post_login_mode'] ?? 'both') === 'passkey_only';
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
        // Serialize management deletes so two simultaneous requests cannot
        // each remove a different credential and leave passkey-only sites locked out.
        $lockPath = $this->store->storageDirectory() . DIRECTORY_SEPARATOR . '.management.lock';
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('パスキーの管理ロックを取得できませんでした。');
        }
        try {
            $this->loadOwned($credentialId);
            if ($this->protectLastCredential && count($this->all()) <= 1) {
                throw new RuntimeException('パスキー専用モードでは最後のパスキーを削除できません。');
            }
            if (!$this->store->delete($credentialId)) {
                throw new RuntimeException('パスキーを削除できませんでした。');
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
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
