<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Themes shipped with a fresh Tomos installation.
 *
 * This is intentionally separate from ThemeRepository::all(): installed
 * themes may include user-added and standalone themes, while first setup may
 * only offer the themes that belong to the core distribution.
 */
final class InitialBundledThemes
{
    /** @var list<string> */
    private const NAMES = [
        'tomos-minimal',
        'tomos-note',
        'tomos-90s',
        'tomos-dark',
        'tomos-journal',
        'tomos-blog',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * Keep repository metadata only for themes that are part of a fresh setup.
     * The original repository result is not mutated.
     *
     * @param array<string,array<string,mixed>> $themes
     * @return array<string,array<string,mixed>>
     */
    public static function only(array $themes): array
    {
        $filtered = [];
        foreach (self::NAMES as $name) {
            if (array_key_exists($name, $themes)) {
                $filtered[$name] = $themes[$name];
            }
        }

        return $filtered;
    }

    public static function contains(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
