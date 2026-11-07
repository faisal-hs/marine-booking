<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\Bulk\BulkUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProviderMonthlyReservationSeeder;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A seeder gives the same data every time it runs. What used to break that, depending on how much garbage earlier code had left in memory:
 * Faker's Generator::__destruct() calls mt_srand() with no seed, so whenever PHP destroyed any Faker object, even in the middle of a seeder run, the
 * global random generator the seeders depended on was silently reseeded and the rest of the run came out different.
 *
 * The seeders now draw only from their OWN engine (BulkSeeder::$random). These tests recreate the cause on purpose.
 */
class SeederDeterminismTest extends TestCase
{
    use RefreshDatabase;

    /** Destroys a Faker object, which reseeds PHP's global random generator: exactly what the garbage collector did by itself. */
    private function destroyAFakerObject(): void
    {
        $faker = new Generator;
        unset($faker);
    }

    private function reservations(): array
    {
        return DB::table('reservations')->orderBy('uuid')->get(['uuid', 'reservation_date', 'status', 'total', 'participants', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }

    private function clearReservations(): void
    {
        foreach (['payments', 'reservation_capacity_locks', 'reservations'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_a_faker_object_destroyed_in_the_middle_of_a_run_does_not_change_the_reservations(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ProviderMonthlyReservationSeeder::class);
        $untouched = $this->reservations();
        $this->assertCount(750, $untouched);
        $this->clearReservations();

        // Every time the seeder writes payments, a Faker object is destroyed under it.
        $hits = 0;
        DB::listen(function ($query) use (&$hits) {
            if (str_contains($query->sql, 'into "payments"')) {
                $hits++;
                $this->destroyAFakerObject();
            }
        });
        $this->seed(ProviderMonthlyReservationSeeder::class);

        $this->assertGreaterThan(10, $hits, 'The interference really happened, in the middle of the run');
        $this->assertSame($untouched, $this->reservations(), 'The same reservations, to the minute and the riyal');
    }

    public function test_the_names_a_bulk_seeder_makes_do_not_depend_on_faker_being_left_alone(): void
    {
        config(['seeding.bulk_scale' => 0.01]);
        $people = fn () => DB::table('users')->orderBy('phone')->get(['phone', 'name', 'user_type'])->map(fn ($r) => (array) $r)->all();

        $this->seed(BulkUserSeeder::class);
        $untouched = $people();
        $this->assertGreaterThan(100, count($untouched));
        DB::table('users')->delete();

        $hits = 0;
        DB::listen(function ($query) use (&$hits) {
            if (str_contains($query->sql, 'into "users"')) {
                $hits++;
                $this->destroyAFakerObject();
            }
        });
        $this->seed(BulkUserSeeder::class);

        $this->assertGreaterThan(0, $hits);
        $this->assertSame($untouched, $people(), 'The same people with the same names');
    }

    public function test_faker_garbage_left_by_earlier_code_does_not_change_the_result(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(ProviderMonthlyReservationSeeder::class);
        $untouched = $this->reservations();
        $this->clearReservations();

        // What earlier tests leave behind: Faker objects in reference cycles, that only the garbage collector will destroy, whenever it happens to run.
        for ($i = 0; $i < 200; $i++) {
            $faker = new Generator;
            $faker->cycle = $faker;
            unset($faker);
        }
        $this->seed(ProviderMonthlyReservationSeeder::class);

        $this->assertSame($untouched, $this->reservations());
    }

    public function test_running_a_seeder_twice_from_scratch_gives_the_same_data_whatever_the_garbage_collector_does(): void
    {
        $this->seed(DatabaseSeeder::class);

        $runs = [];
        foreach ([true, false, true] as $collecting) {
            $collecting ? gc_enable() : gc_disable();
            $this->clearReservations();
            $this->seed(ProviderMonthlyReservationSeeder::class);
            $runs[] = $this->reservations();
        }
        gc_enable();

        $this->assertSame($runs[0], $runs[1]);
        $this->assertSame($runs[0], $runs[2]);
    }

    public function test_no_seeder_draws_from_a_source_that_anything_else_can_disturb(): void
    {
        // Allowed to name these: nothing. Every random choice a seeder makes goes through BulkSeeder's own engine ($this->rand(), pick(), chance(),
        // weighted(), sampleKeys(), fakeName()...). The one file that may call fake() is BulkSeeder itself, for the name helper.
        $forbidden = ['mt_rand', 'rand', 'srand', 'mt_srand', 'array_rand', 'shuffle', 'str_shuffle', 'random_int', 'random_bytes', 'lcg_value', 'uniqid'];
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(database_path('seeders'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = token_get_all(file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                $previous = $this->significant($tokens, $i, -1);
                $next = $this->significant($tokens, $i, 1);
                $isCall = $next === '(' && ! in_array($previous, ['->', '::', 'function'], true) && ! (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true));
                $name = $token[1];
                $where = str_replace(database_path('seeders').'/', '', $file->getPathname()).':'.$token[2];

                if ($isCall && in_array($name, $forbidden, true)) {
                    $violations[] = "{$where} calls {$name}()";
                }
                if ($isCall && $name === 'fake' && $file->getFilename() !== 'BulkSeeder.php') {
                    $violations[] = "{$where} calls fake(): use \$this->fakeName()";
                }
                // Str::random() / Str::uuid() / Str::orderedUuid(): different every time.
                if ($isCall && in_array($name, ['random', 'uuid', 'orderedUuid'], true) && is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    $violations[] = "{$where} calls ::{$name}(): not repeatable";
                }
            }
        }

        $this->assertSame([], $violations, "These seeders use a random source that is not their own:\n  ".implode("\n  ", $violations));
    }

    /** The nearest token that is not whitespace or a comment, in one direction. */
    private function significant(array $tokens, int $from, int $step): mixed
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }
}
