<?php

declare(strict_types=1);

namespace Tomos;

final class AnalyticsConfigWriter
{
    public static function update(array $currentConfig, string $measurementId): array
    {
        [$normalized, $errors] = Ga4::validateInput($measurementId);
        if ($errors !== []) {
            return [$currentConfig, $errors];
        }

        $newConfig = $currentConfig;
        if (!isset($newConfig['analytics']) || !is_array($newConfig['analytics'])) {
            $newConfig['analytics'] = [];
        }
        $newConfig['analytics']['ga4_measurement_id'] = $normalized;

        return [$newConfig, []];
    }
}
