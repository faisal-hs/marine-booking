<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\CategorySeeder;
use Database\Seeders\Concerns\RefusesToRunInProduction;
use Database\Seeders\FeatureDefinitionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;

/**
 * Runs every Bulk seeder, in dependency order, on top of the roles and the twelve demo categories.
 *
 *     php artisan db:seed --class="Database\\Seeders\\Bulk\\BulkDatabaseSeeder"
 *
 * BULK_SEED_SCALE sets the size (1 = about 1.3 million rows, 370 MB in SQLite, seeded in about 75 seconds; 0.1 = a tenth; 0.01 = a smoke test).
 * Every seeder is also runnable on its own (--class), and says which one to run first if something it builds on is
 * missing. Running it again adds nothing; raising the scale and running it again adds the difference.
 *
 * It refuses to run in production.
 */
class BulkDatabaseSeeder extends Seeder
{
    use RefusesToRunInProduction;

    /** One seeder per model, plus the notification feed, in the order they depend on each other. */
    public const SEEDERS = [
        BulkCategorySeeder::class,
        BulkFeatureDefinitionSeeder::class,
        BulkUserSeeder::class,
        BulkAdminSeeder::class,
        BulkProviderSeeder::class,
        BulkProviderRequestSeeder::class,
        BulkProviderProfileChangeSeeder::class,
        BulkVerificationDocumentTypeSeeder::class,
        BulkProviderDocumentSeeder::class,
        BulkProviderDocumentEventSeeder::class,
        BulkCustomerSeeder::class,
        BulkActivitySeeder::class,
        BulkActivitySchedulePeriodSeeder::class,
        BulkActivityBookingOptionSeeder::class,
        BulkActivityPricingRuleSeeder::class,
        BulkActivityImageSeeder::class,
        BulkActivityFeatureValueSeeder::class,
        BulkActivityRequirementSeeder::class,
        BulkActivityInclusionSeeder::class,
        BulkActivityOptionSeeder::class,
        BulkActivityAvailabilityOverrideSeeder::class,
        BulkReservationSeeder::class,
        BulkReservationCapacityLockSeeder::class,
        BulkPaymentSeeder::class,
        BulkWalletEntrySeeder::class,
        BulkWithdrawalSeeder::class,
        BulkReviewSeeder::class,
        BulkFavoriteSeeder::class,
        BulkDeviceSeeder::class,
        BulkOtpSeeder::class,
        BulkNotificationSeeder::class,
        BulkBroadcastSeeder::class,
    ];

    public function run(): void
    {
        $this->refuseInProduction();

        $this->command?->info('Bulk data at scale '.config('seeding.bulk_scale').' (BULK_SEED_SCALE).');

        // The roles and the twelve demo categories (with their feature definitions) are the base everything builds on.
        $this->call([RolesAndPermissionsSeeder::class, CategorySeeder::class, FeatureDefinitionSeeder::class], silent: true);

        $started = microtime(true);
        $this->call(self::SEEDERS, silent: true);

        $this->command?->info(sprintf('Done in %.1fs.', microtime(true) - $started));
    }
}
