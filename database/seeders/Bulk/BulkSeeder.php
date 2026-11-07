<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Database\Seeders\Concerns\RefusesToRunInProduction;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * The base of every Bulk seeder. It gives them:
 *
 *  - DETERMINISM: the random generators are seeded from the class name and BULK_SEED_RANDOM, so a
 *    given scale always produces the same data, whatever order the seeders run in.
 *  - IDEMPOTENCE: rows carry deterministic keys (uuid, phone, slug ...) and are written with
 *    "insert or ignore", so running a seeder twice adds nothing, and raising the scale and running
 *    it again adds only the difference.
 *  - SPEED: rows are generated lazily and written in chunks inside one transaction.
 *  - SAFETY: they refuse to run in production, and say which seeder to run first when a
 *    prerequisite table is empty.
 *
 * A seeder only implements seed().
 */
abstract class BulkSeeder extends Seeder
{
    use RefusesToRunInProduction;

    /** A fixed namespace, so uuid("user-customer", 7) is the same uuid on every machine. */
    private const UUID_NAMESPACE = '6b1d2f10-3c1a-4b6e-9d11-0f6c1a9d7e55';

    /** Phone number ranges. They stay clear of the demo seeders (05...), and tell the kinds apart. */
    public const CUSTOMER_PHONE_PREFIX = '07';

    public const PROVIDER_PHONE_PREFIX = '06';

    public const STAFF_PHONE_PREFIX = '08';

    /** Bulk activities all end their slug with -bk<number>, which is how they are told from the demo ones. */
    public const ACTIVITY_SLUG_MARK = '-bk';

    protected Carbon $now;

    protected int $created = 0;

    /**
     * The seeder's OWN random engine, started from its seed. Every draw goes through it, never through PHP's global generator: anything can reseed
     * the global one at any moment (Faker's Generator::__destruct() calls mt_srand() with no seed whenever the garbage collector destroys a Faker
     * object), and a seeder that depended on it gave different data from one run to the next depending on how much garbage earlier code had left.
     */
    protected Randomizer $random;

    /** Fills the tables this seeder owns. */
    abstract protected function seed(): void;

    final public function run(): void
    {
        $this->refuseInProduction();

        $this->now = Carbon::now()->startOfMinute();
        $seed = (crc32(static::class) ^ (int) config('seeding.seed')) & 0x7FFFFFFF;

        // Every draw comes from this engine, which nothing else can reach (see $random). Faker is used only for names, and fakeName() reseeds it from
        // the engine each time, so a Faker object being destroyed, or anything else touching PHP's global generator, cannot change what is seeded.
        $this->startRandom($seed);

        $started = microtime(true);
        DB::transaction(fn () => $this->seed());

        $this->command?->getOutput()->writeln(sprintf(
            '  <info>%s</info> %s rows in %.1fs',
            str_pad(class_basename(static::class), 40),
            str_pad(number_format($this->created), 9, ' ', STR_PAD_LEFT),
            microtime(true) - $started
        ));
    }

    // --- Volume -----------------------------------------------------------------------------

    /** A volume at scale 1, multiplied by BULK_SEED_SCALE (never below 1). */
    protected function scaled(int $atScaleOne): int
    {
        return max(1, (int) round($atScaleOne * max(0.0001, (float) config('seeding.bulk_scale'))));
    }

    // --- Deterministic randomness -----------------------------------------------------------------

    /** Starts the seeder's own random engine from a seed. run() does it; a test of the helpers does it by hand. */
    protected function startRandom(int $seed): void
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    protected function rand(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    protected function chance(float $percent): bool
    {
        return $this->random->getInt(0, 99999) < $percent * 1000;
    }

    protected function pick(array $list): mixed
    {
        return $list[$this->random->pickArrayKeys($list, 1)[0]];
    }

    /**
     * $count distinct keys of the list, left in the order the list has them (what array_rand() gives, from this seeder's own engine).
     *
     * @return list<int|string>
     */
    protected function sampleKeys(array $list, int $count): array
    {
        $chosen = $this->random->pickArrayKeys($list, $count);

        return array_values(array_filter(array_keys($list), fn ($key) => in_array($key, $chosen, true)));
    }

    /** A name from Faker, reseeded from this seeder's engine so it does not depend on Faker's (or PHP's global) random state. */
    protected function fakeName(bool $arabic = false): string
    {
        $faker = $arabic ? fake('ar_SA') : fake();
        $faker->seed($this->random->getInt(0, 0x7FFFFFFF));

        return $faker->name();
    }

    /** A raw random integer, 0 to mt_getrandmax(). */
    protected function raw(): int
    {
        return $this->random->getInt(0, mt_getrandmax());
    }

    protected function stable(string $key, float $percent): bool
    {
        return (crc32($key.'|'.config('seeding.seed')) % 100000) < $percent * 1000;
    }

    /**
     * @param  array<string, int|float>  $weights  e.g. ['published' => 62, 'draft' => 12]
     */
    protected function weighted(array $weights): string
    {
        $roll = $this->random->getInt(1, (int) round(array_sum($weights) * 1000)) / 1000;

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    /** A lazily skewed index in [0, $count): low indexes are chosen far more often (popular items). */
    protected function skewed(int $count): int
    {
        $r = $this->raw() / mt_getrandmax();

        return min($count - 1, (int) floor($count * $r * $r));
    }

    protected function uuid(string $kind, int|string $key): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, "{$kind}-{$key}")->toString();
    }

    protected function daysAgo(int $min, int $max): Carbon
    {
        return $this->now->copy()->subDays($this->rand($min, $max))->subMinutes($this->rand(0, 1439));
    }

    protected function ts(Carbon $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    // --- Writing -----------------------------------------------------------------------------------

    /**
     * Writes rows in chunks, skipping any that already exist (unique keys). Returns how many were new.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    protected function insert(string $table, iterable $rows): int
    {
        $buffer = [];
        $limit = null;
        $written = 0;

        foreach ($rows as $row) {
            // The row width is only known once there is a row. SQLite allows about 32,000 bound values per statement.
            $limit ??= max(1, min(1000, intdiv(30000, max(1, count($row)))));
            $buffer[] = $row;

            if (count($buffer) >= $limit) {
                $written += DB::table($table)->insertOrIgnore($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $written += DB::table($table)->insertOrIgnore($buffer);
        }

        $this->created += $written;

        return $written;
    }

    // --- Prerequisites -------------------------------------------------------------------------------

    /**
     * Stops with a clear message when the data this seeder builds on is missing.
     *
     * @param  callable(Builder): Builder|null  $scope
     */
    protected function needs(string $table, string $firstRunThis, ?callable $scope = null): void
    {
        $query = DB::table($table);
        $query = $scope ? $scope($query) : $query;

        if (! $query->exists()) {
            throw new RuntimeException(sprintf(
                '%s needs rows in "%s", and there are none. Run %s first (or run BulkDatabaseSeeder, which does everything in order).',
                class_basename(static::class),
                $table,
                $firstRunThis
            ));
        }
    }

    protected function line(string $message): void
    {
        $this->command?->getOutput()->writeln("  {$message}");
    }
}
