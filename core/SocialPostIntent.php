<?php

declare(strict_types=1);

namespace Tomos;

final class SocialPostIntent
{
    public bool $blueskyEnabled;
    public ?string $customText;

    public function __construct(bool $blueskyEnabled, ?string $customText)
    {
        $this->blueskyEnabled = $blueskyEnabled;
        $this->customText = $customText;
    }

    /** @param array<string,mixed> $metadata */
    public static function fromMetadata(array $metadata): self
    {
        $providers = self::providers($metadata['social'] ?? null);
        $customText = self::customText($metadata['social_text'] ?? null);

        return new self(in_array('bluesky', $providers, true), $customText);
    }

    /** @return string[] */
    private static function providers($value): array
    {
        $items = is_array($value) ? $value : [$value];
        $providers = [];

        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $provider = strtolower(trim((string) $item));
            if ($provider === '' || in_array($provider, $providers, true)) {
                continue;
            }
            $providers[] = $provider;
        }

        return $providers;
    }

    private static function customText($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
