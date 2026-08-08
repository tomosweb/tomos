<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PasskeyManagementService
{
    private PasskeyEnvironment $environment;
    private PasskeyCredentialStore $store;

    public function __construct(PasskeyEnvironment $environment, PasskeyCredentialStore $store)
    {
        $this->environment = $environment;
        $this->store = $store;
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
        $this->loadOwned($credentialId);
        if (!$this->store->delete($credentialId)) {
            throw new RuntimeException('パスキーを削除できませんでした。');
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
