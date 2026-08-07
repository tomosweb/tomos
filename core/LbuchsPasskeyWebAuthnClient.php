<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;
use lbuchs\WebAuthn\WebAuthn;

final class LbuchsPasskeyWebAuthnClient implements PasskeyWebAuthnClient
{
    public function createRegistrationOptions(string $rpId, array $excludeCredentialIds): array
    {
        if (!class_exists(WebAuthn::class)) {
            throw new RuntimeException('WebAuthn library is not available.');
        }

        $webauthn = new WebAuthn('Tomos', $rpId, ['none'], true);
        $args = $webauthn->getCreateArgs(
            hash('sha256', $rpId . '|tomos-admin', true),
            'tomos-admin',
            'Tomos administrator',
            60,
            true,
            'required',
            false,
            $excludeCredentialIds
        );

        return [
            'public_key' => $args->publicKey,
            'challenge' => $webauthn->getChallenge()->getBinaryString(),
        ];
    }

    public function verifyRegistration(string $rpId, array $payload, string $challenge): array
    {
        if (!class_exists(WebAuthn::class)) {
            throw new RuntimeException('WebAuthn library is not available.');
        }

        $clientData = $this->decodeBase64((string) ($payload['clientDataJSON'] ?? ''));
        $attestation = $this->decodeBase64((string) ($payload['attestationObject'] ?? ''));
        if ($clientData === null || $attestation === null || $challenge === '') {
            throw new RuntimeException('Invalid registration payload.');
        }

        $webauthn = new WebAuthn('Tomos', $rpId, ['none'], true);
        $data = $webauthn->processCreate(
            $clientData,
            $attestation,
            $challenge,
            true,
            true,
            false,
            false
        );

        $transports = is_array($payload['transports'] ?? null) ? array_values($payload['transports']) : [];

        return [
            'credential_id' => $this->base64UrlEncode((string) $data->credentialId),
            'public_key' => base64_encode((string) $data->credentialPublicKey),
            'sign_count' => $data->signatureCounter === null ? 0 : (int) $data->signatureCounter,
            'transports' => $transports,
        ];
    }

    private function decodeBase64(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
