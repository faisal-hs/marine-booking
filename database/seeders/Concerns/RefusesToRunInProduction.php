<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * For seeders that create demo data: accounts with a known password
 * (AdminSeeder creates a super admin whose password is "password"), fake
 * providers, fake customers and sample activities. Running one against a
 * live database would hand out working credentials, so it refuses instead.
 *
 * Only "production" is blocked, so local, testing and staging keep working.
 * The reference-data seeders (roles and permissions, categories, feature
 * definitions) deliberately do NOT use this trait.
 */
trait RefusesToRunInProduction
{
    protected function refuseInProduction(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(sprintf(
                '%s creates demo data with known credentials and must not run in production.',
                class_basename(static::class)
            ));
        }
    }
}
