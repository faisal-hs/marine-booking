<?php

namespace Tests\Concerns;

use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The demo data (DatabaseSeeder) and whatever a test class needs on top of it (`seedDemo()`), seeded ONCE for the whole class into a throwaway database file,
 * the way UsesSeededBulkDatabase does it for the bulk data. The tests read it and do not change it (one that must change something does it inside a transaction
 * and rolls it back), so they can share it; that is what keeps a class of twenty tests to one build instead of twenty.
 */
trait UsesSeededDemoDatabase
{
    private static ?string $demoDatabase = null;

    private static bool $demoSeeded = false;

    /** The minute the data was seeded in (the seeders decide what is "a day old" relative to this). */
    private static ?Carbon $demoSeededAt = null;

    /** What to seed on top of the demo data. */
    abstract protected function seedDemo(): void;

    protected function demoSeededAt(): Carbon
    {
        return self::$demoSeededAt->copy();
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$demoDatabase ??= storage_path('framework/testing/demo-'.getmypid().'-'.crc32(static::class).'.sqlite');
        config(['database.connections.sqlite.database' => self::$demoDatabase]);
        DB::purge('sqlite');
        $disk = config('providers.documents.disk');

        if (! self::$demoSeeded) {
            Storage::fake($disk);
            @mkdir(dirname(self::$demoDatabase), 0777, true);
            @unlink(self::$demoDatabase);
            touch(self::$demoDatabase);
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$demoSeededAt = Carbon::now()->startOfMinute();
            $this->seed(DatabaseSeeder::class);
            $this->seedDemo();
            self::$demoSeeded = true;
        } else {
            // The same throwaway files as the first test, without wiping them.
            Storage::persistentFake($disk);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$demoDatabase !== null) {
            @unlink(self::$demoDatabase);
        }
        self::$demoDatabase = null;
        self::$demoSeeded = false;
        self::$demoSeededAt = null;
        gc_collect_cycles();
        parent::tearDownAfterClass();
    }
}
