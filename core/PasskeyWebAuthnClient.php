<?php

declare(strict_types=1);

namespace Tomos;

interface PasskeyWebAuthnClient
{
    /**
     * @param array<int,string> $excludeCredentialIds Binary credential IDs.
     * @return array{public_key:mixed,challenge:string}
     */
    public function createRegistrationOptions(string $rpId, array $excludeCredentialIds): array;

    /**
     * @param array<string,mixed> $payload
     * @return array{credential_id:string,public_key:string,sign_count:int,transports:array<int,string>}
     */
    public function verifyRegistration(
        string $rpId,
        string $expectedOrigin,
        array $payload,
        string $challenge
    ): array;

    /**
     * @param array<int,string> $allowCredentialIds Binary credential IDs.
     * @return array{public_key:mixed,challenge:string}
     */
    public function createAuthenticationOptions(string $rpId, array $allowCredentialIds): array;

    /**
     * @param array<string,mixed> $payload
     * @return array{sign_count:int}
     */
    public function verifyAuthentication(
        string $rpId,
        string $expectedOrigin,
        array $payload,
        string $challenge,
        string $publicKey,
        int $storedSignCount
    ): array;
}
