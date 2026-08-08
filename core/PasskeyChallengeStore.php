<?php

declare(strict_types=1);

namespace Tomos;

final class PasskeyChallengeStore
{
    private const SESSION_KEY = 'tomos_passkey_challenges';

    private int $now;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /** @param array<string,mixed> $session */
    public function remember(array &$session, string $purpose, string $challenge, int $ttlSeconds = 120): bool
    {
        if (!$this->validPurpose($purpose) || $challenge === '' || $ttlSeconds < 1 || $ttlSeconds > 600) {
            return false;
        }

        $all = is_array($session[self::SESSION_KEY] ?? null) ? $session[self::SESSION_KEY] : [];
        $all[$purpose] = [
            'challenge' => base64_encode($challenge),
            'expires_at' => $this->now + $ttlSeconds,
        ];
        $session[self::SESSION_KEY] = $all;
        return true;
    }

    /** @param array<string,mixed> $session */
    public function consume(array &$session, string $purpose): ?string
    {
        if (!$this->validPurpose($purpose)) {
            return null;
        }

        $all = is_array($session[self::SESSION_KEY] ?? null) ? $session[self::SESSION_KEY] : [];
        $record = is_array($all[$purpose] ?? null) ? $all[$purpose] : null;
        unset($all[$purpose]);

        if ($all === []) {
            unset($session[self::SESSION_KEY]);
        } else {
            $session[self::SESSION_KEY] = $all;
        }

        if ($record === null || (int) ($record['expires_at'] ?? 0) < $this->now) {
            return null;
        }

        $decoded = base64_decode((string) ($record['challenge'] ?? ''), true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    /** @param array<string,mixed> $session */
    public function clear(array &$session, ?string $purpose = null): void
    {
        if ($purpose === null) {
            unset($session[self::SESSION_KEY]);
            return;
        }

        if (!$this->validPurpose($purpose)) {
            return;
        }

        $all = is_array($session[self::SESSION_KEY] ?? null) ? $session[self::SESSION_KEY] : [];
        unset($all[$purpose]);
        if ($all === []) {
            unset($session[self::SESSION_KEY]);
        } else {
            $session[self::SESSION_KEY] = $all;
        }
    }

    private function validPurpose(string $purpose): bool
    {
        return in_array($purpose, ['register', 'authenticate', 'password-reset', 'server-recovery-register'], true);
    }
}
