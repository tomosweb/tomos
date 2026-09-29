<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthPublicEndpointPreparation
{
    /**
     * Prepare the public Metadata/JWKS response backing before OAuth starts.
     *
     * @return array{status:string,static_backing_enabled:bool,migration_status:string}
     */
    public static function prepare(string $rootDir, array $config): array
    {
        try {
            $clientKey = (new BlueskyOAuthKeyStore($rootDir))->loadOrCreate();
        } catch (\Throwable $exception) {
            throw new BlueskyOAuthPublicEndpointPreparationException('client_key_unavailable');
        }
        if (!is_array($clientKey)) {
            throw new BlueskyOAuthPublicEndpointPreparationException('client_key_unavailable');
        }

        try {
            BlueskyOAuthStaticBacking::generate($rootDir, $config);
        } catch (\Throwable $exception) {
            throw new BlueskyOAuthPublicEndpointPreparationException('static_backing_generation_failed');
        }

        $diagnostic = self::inspect($rootDir, 'htaccess_diagnostics_failed');
        $migrationStatus = 'not_needed';
        if (!self::staticBackingEnabled($diagnostic)) {
            try {
                $plan = BlueskyOAuthHtaccessMigration::inspectMigration($rootDir);
            } catch (\Throwable $exception) {
                throw new BlueskyOAuthPublicEndpointPreparationException('htaccess_migration_inspection_failed');
            }
            if (empty($plan['can_migrate'])) {
                throw new BlueskyOAuthPublicEndpointPreparationException('htaccess_migration_not_safe');
            }

            try {
                $migration = BlueskyOAuthHtaccessMigration::migrate($rootDir);
            } catch (\Throwable $exception) {
                throw new BlueskyOAuthPublicEndpointPreparationException('htaccess_migration_failed');
            }
            $migrationStatus = (string) ($migration['status'] ?? '');
            if (!in_array($migrationStatus, ['migrated', 'not_needed'], true)) {
                throw new BlueskyOAuthPublicEndpointPreparationException('htaccess_migration_failed');
            }
            $diagnostic = self::inspect($rootDir, 'post_migration_diagnostics_failed');
        }

        if (!self::staticBackingEnabled($diagnostic)) {
            throw new BlueskyOAuthPublicEndpointPreparationException('static_backing_not_ready');
        }

        return [
            'status' => 'managed',
            'static_backing_enabled' => true,
            'migration_status' => $migrationStatus,
        ];
    }

    /** @return array<string,mixed> */
    private static function inspect(string $rootDir, string $failureCode): array
    {
        try {
            $diagnostic = BlueskyOAuthHtaccessDiagnostics::inspect($rootDir);
        } catch (\Throwable $exception) {
            throw new BlueskyOAuthPublicEndpointPreparationException($failureCode);
        }
        if (!is_array($diagnostic)) {
            throw new BlueskyOAuthPublicEndpointPreparationException($failureCode);
        }
        return $diagnostic;
    }

    /** @param array<string,mixed> $diagnostic */
    private static function staticBackingEnabled(array $diagnostic): bool
    {
        return ($diagnostic['status'] ?? '') === 'managed'
            && !empty($diagnostic['static_backing_enabled']);
    }
}
