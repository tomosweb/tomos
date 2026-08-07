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
    public function verifyRegistration(string $rpId, array $payload, string $challenge): array;
}
