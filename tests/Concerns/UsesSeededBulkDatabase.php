<?php

namespace Tests\Concerns;

use Database\Seeders\Bulk\BulkDatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds the Bulk data ONCE per test class, into a file database that every test method of the class then reads.
 *
 * The usual in-memory database is rebuilt for every test, which would re-seed (and re-wait) a thousand
 * reservations dozens of times. Classes that use this only READ the seeded data; tests that change it (running a
 * seeder twice, raising the scale, ...) live in classes of their own with a database of their own.
 *
 * The provider documents the seeders write go to a throwaway disk, never to the real storage folder: the first test
 * of the class empties it before seeding, the later ones keep what was written.
 *
 * The scale is BULK_TEST_SCALE (default 0.05: about 1,300 users, 750 activities and 7,500 reservations). Set it to 1 to
 * run the same checks against the full-size data set.
 */
trait UsesSeededBulkDatabase
{
    private static ?string $bulkDatabase = null;

    private static bool $bulkSeeded = false;

    protected function bulkScale(): float
    {
        return (float) (getenv('BULK_TEST_SCALE') ?: 0.05);
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$bulkDatabase ??= storage_path('framework/testing/bulk-'.getmypid().'-'.crc32(static::class).'.sqlite');
        config(['database.connections.sqlite.database' => self::$bulkDatabase, 'seeding.bulk_scale' => $this->bulkScale()]);
        DB::purge('sqlite');

        $disk = config('providers.documents.disk');

        if (! self::$bulkSeeded) {
            Storage::fake($disk);

            @mkdir(dirname(self::$bulkDatabase), 0777, true);
            @unlink(self::$bulkDatabase);
            touch(self::$bulkDatabase);

            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->seed(BulkDatabaseSeeder::class);

            self::$bulkSeeded = true;
        } else {
            // Same throwaway files as the first test, without wiping them.
            Storage::persistentFake($disk);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$bulkDatabase !== null) {
            @unlink(self::$bulkDatabase);
        }

        self::$bulkDatabase = null;
        self::$bulkSeeded = false;

        // Hand the seeded rows' memory back before the next test class starts (the suite is one long process).
        gc_collect_cycles();

        parent::tearDownAfterClass();
    }
}
