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

    public function createAuthenticationOptions(string $rpId, array $allowCredentialIds): array
    {
        if (!class_exists(WebAuthn::class)) {
            throw new RuntimeException('WebAuthn library is not available.');
        }

        $webauthn = new WebAuthn('Tomos', $rpId, ['none'], true);
        $args = $webauthn->getGetArgs(
            $allowCredentialIds,
            60,
            false,
            false,
            false,
            true,
            true,
            'required'
        );

        return [
            'public_key' => $args->publicKey,
            'challenge' => $webauthn->getChallenge()->getBinaryString(),
        ];
    }

    public function verifyAuthentication(
        string $rpId,
        array $payload,
        string $challenge,
        string $publicKey,
        int $storedSignCount
    ): array {
        if (!class_exists(WebAuthn::class)) {
            throw new RuntimeException('WebAuthn library is not available.');
        }

        $clientData = $this->decodeBase64((string) ($payload['clientDataJSON'] ?? ''));
        $authenticatorData = $this->decodeBase64((string) ($payload['authenticatorData'] ?? ''));
        $signature = $this->decodeBase64((string) ($payload['signature'] ?? ''));
        $decodedPublicKey = base64_decode($publicKey, true);
        if ($clientData === null
            || $authenticatorData === null
            || $signature === null
            || !is_string($decodedPublicKey)
            || $decodedPublicKey === ''
            || $challenge === ''
            || $storedSignCount < 0
        ) {
            throw new RuntimeException('Invalid authentication payload.');
        }

        $webauthn = new WebAuthn('Tomos', $rpId, ['none'], true);
        $webauthn->processGet(
            $clientData,
            $authenticatorData,
            $signature,
            $decodedPublicKey,
            $challenge,
            $storedSignCount,
            true,
            true
        );

        $counter = $webauthn->getSignatureCounter();
        return [
            'sign_count' => $counter === null ? $storedSignCount : max(0, (int) $counter),
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
