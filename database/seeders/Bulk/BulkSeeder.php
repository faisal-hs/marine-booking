<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
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

    /** Fills the tables this seeder owns. */
    abstract protected function seed(): void;

    final public function run(): void
    {
        $this->refuseInProduction();

        $this->now = Carbon::now()->startOfMinute();
        $seed = (crc32(static::class) ^ (int) config('seeding.seed')) & 0x7FFFFFFF;
        mt_srand($seed);
        fake()->seed($seed);
        fake('ar_SA')->seed($seed);

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

    protected function rand(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /** True $percent times in a hundred. */
    protected function chance(float $percent): bool
    {
        return mt_rand(0, 99999) < $percent * 1000;
    }

    /** @param  array<int|string, mixed>  $list */
    protected function pick(array $list): mixed
    {
        return $list[array_rand($list)];
    }

    /**
     * A yes/no that depends ONLY on $key (and the seed), never on how many times a seeder has run.
     *
     * Use it for "does this one have any?" decisions. A seeder that fills in what is missing re-examines the items that
     * legitimately have nothing (an activity with no add-ons, a booking nobody paid or reviewed); if it rolled
     * the dice again for them on every run, each run would add more. With chance() that is exactly what happened.
     */
    protected function stable(string $key, float $percent): bool
    {
        return (crc32($key.'|'.config('seeding.seed')) % 100000) < $percent * 1000;
    }

    /**
     * @param  array<string, int|float>  $weights  e.g. ['published' => 62, 'draft' => 12]
     */
    protected function weighted(array $weights): string
    {
        $roll = mt_rand(1, (int) round(array_sum($weights) * 1000)) / 1000;

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
        $r = mt_rand() / mt_getrandmax();

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
