<?php

declare(strict_types=1);

namespace Tomos;

final class Es256Jwt
{
    /** @param array<string,mixed> $header @param array<string,mixed> $claims */
    public static function sign(array $header, array $claims, string $privatePem): string
    {
        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $claimsJson = json_encode($claims, JSON_UNESCAPED_SLASHES);
        if (!is_string($headerJson) || !is_string($claimsJson)) {
            throw new \RuntimeException('JWT payload could not be encoded.');
        }

        $encodedHeader = self::base64Url($headerJson);
        $encodedClaims = self::base64Url($claimsJson);
        $input = $encodedHeader . '.' . $encodedClaims;

        $signatureDer = '';
        if (!openssl_sign($input, $signatureDer, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('JWT could not be signed.');
        }

        return $input . '.' . self::base64Url(self::derToJose($signatureDer, 32));
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function derToJose(string $der, int $partLength): string
    {
        $offset = 0;
        if (ord($der[$offset] ?? "\0") !== 0x30) {
            throw new \RuntimeException('Invalid ECDSA signature.');
        }
        $offset++;
        self::readLength($der, $offset);

        if (ord($der[$offset] ?? "\0") !== 0x02) {
            throw new \RuntimeException('Invalid ECDSA signature.');
        }
        $offset++;
        $rLength = self::readLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;

        if (ord($der[$offset] ?? "\0") !== 0x02) {
            throw new \RuntimeException('Invalid ECDSA signature.');
        }
        $offset++;
        $sLength = self::readLength($der, $offset);
        $s = substr($der, $offset, $sLength);

        $r = self::normalizeInteger($r, $partLength);
        $s = self::normalizeInteger($s, $partLength);

        return $r . $s;
    }

    private static function readLength(string $der, int &$offset): int
    {
        $first = ord($der[$offset] ?? "\0");
        $offset++;
        if (($first & 0x80) === 0) {
            return $first;
        }

        $count = $first & 0x7f;
        if ($count < 1 || $count > 4) {
            throw new \RuntimeException('Invalid DER length.');
        }

        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset] ?? "\0");
            $offset++;
        }
        return $length;
    }

    private static function normalizeInteger(string $value, int $length): string
    {
        while (strlen($value) > $length && $value[0] === "\0") {
            $value = substr($value, 1);
        }
        if (strlen($value) > $length) {
            throw new \RuntimeException('ECDSA integer is too large.');
        }
        return str_pad($value, $length, "\0", STR_PAD_LEFT);
    }
}
