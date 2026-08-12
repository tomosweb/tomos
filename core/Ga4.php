<?php

declare(strict_types=1);

namespace Tomos;

final class Ga4
{
    private const MAX_ID_LENGTH = 64;

    public static function validateInput(string $value): array
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return ['', []];
        }

        if (strlen($normalized) > self::MAX_ID_LENGTH || preg_match('/\AG-[A-Z0-9]+\z/', $normalized) !== 1) {
            return ['', ['GA4測定IDは、G-から始まる半角英数字で入力してください。']];
        }

        return [$normalized, []];
    }

    public static function measurementId(array $config): string
    {
        $value = (string) ($config['analytics']['ga4_measurement_id'] ?? '');
        [$measurementId, $errors] = self::validateInput($value);

        return $errors === [] ? $measurementId : '';
    }

    public static function headHtml(array $config, string $nonce = ''): string
    {
        $measurementId = self::measurementId($config);
        if ($measurementId === '') {
            return '';
        }

        $attributeId = htmlspecialchars($measurementId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonId = json_encode(
            $measurementId,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($jsonId)) {
            return '';
        }

        $nonceAttribute = $nonce !== ''
            ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';

        return '<!-- Google tag (gtag.js) -->' . "\n"
            . '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $attributeId . '"' . $nonceAttribute . '></script>' . "\n"
            . '<script' . $nonceAttribute . '>' . "\n"
            . '  window.dataLayer = window.dataLayer || [];' . "\n"
            . '  function gtag(){dataLayer.push(arguments);}' . "\n"
            . "  gtag('js', new Date());" . "\n\n"
            . "  gtag('config', " . $jsonId . ');' . "\n"
            . '</script>';
    }
}
