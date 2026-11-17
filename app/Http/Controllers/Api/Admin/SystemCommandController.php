<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Admin\System\MigrateFreshRequest;
use App\Http\Requests\Admin\System\MigrationsRequest;
use App\Http\Requests\Admin\System\SeedRequest;
use App\Services\SystemCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan migrate`, `migrate:fresh` and `db:seed` as an API, for an installation with no shell. Super admin only: each command behind a permission of its own
 * (system.migrate, system.migrate_fresh, system.seed), one at a time, every run logged to the log FILE with who ran it (never to the database, which migrate:fresh empties).
 *
 *   GET  /system/migrations     which migrations have run and which are pending
 *   POST /system/migrate        runs the pending migrations (safe to repeat)
 *   POST /system/migrate-fresh  DROPS EVERY TABLE and migrates again: refused in production unless switched on, and needs the phrase and the admin's password
 *   GET  /system/seeders        the seeders the API will run, and whether each may run here
 *   POST /system/seed           runs one of them
 */
class SystemCommandController extends BaseApiController
{
    public function __construct(private readonly SystemCommandService $commands) {}

    public function migrations(MigrationsRequest $request)
    {
        return $this->success($this->commands->migrations(), 'Migration status.');
    }

    public function migrate(MigrationsRequest $request)
    {
        $before = $this->commands->migrations();
        $result = $this->commands->migrate();

        return $this->finish($request, 'migrate', $result, 'Migrations run.', fn () => [
            'ran' => array_values(array_diff($before['pending'], $this->commands->migrations()['pending'])),
            'pending' => $this->commands->migrations()['pending'],
        ]);
    }

    public function migrateFresh(MigrateFreshRequest $request)
    {
        if (! $this->commands->migrateFreshAllowedHere()) {
            Log::warning('migrate:fresh refused from the admin API: production', ['user_id' => $request->user()->id]);

            return $this->fail(403, 'MIGRATE_FRESH_DISABLED_IN_PRODUCTION', 'migrate:fresh drops every table, so it is not run in production unless SYSTEM_COMMANDS_ALLOW_MIGRATE_FRESH_IN_PRODUCTION is set to true in the environment.');
        }

        $seeder = $request->seeder();

        if ($seeder !== null && ! $this->commands->seederAllowedHere($seeder)) {
            return $this->fail(403, 'SEEDER_NOT_ALLOWED_IN_PRODUCTION', "{$seeder} makes demo data (accounts with a known password), which is not made in production.");
        }

        // The tables are about to be dropped, and so is the token this request came with: Log goes to a file, never to the database.
        Log::warning('migrate:fresh run from the admin API', ['user_id' => $request->user()->id, 'seeder' => $seeder, 'environment' => app()->environment()]);
        $result = $this->commands->fresh($seeder);

        return $this->finish($request, 'migrate:fresh', $result, $seeder ? "The database was dropped, migrated and seeded with {$seeder}." : 'The database was dropped and migrated. It is empty: nobody can sign in until it is seeded.', fn () => [
            'seeded_with' => $seeder,
            'empty' => $seeder === null,
            // The token this request used was in the database that was dropped.
            'signed_out' => true,
        ]);
    }

    public function seeders(MigrationsRequest $request)
    {
        // The seeders may be looked at by whoever may run any of the three; running each needs system.seed.
        $list = [];

        foreach ($this->commands->seeders() as $name => $kind) {
            $list[] = ['class' => $name, 'kind' => $kind, 'allowed_here' => $this->commands->seederAllowedHere($name)];
        }

        return $this->success(['environment' => app()->environment(), 'seeders' => $list], 'Seeders.');
    }

    public function seed(SeedRequest $request)
    {
        $name = $request->validated('class');

        if (! $this->commands->seederAllowedHere($name)) {
            return $this->fail(403, 'SEEDER_NOT_ALLOWED_IN_PRODUCTION', "{$name} makes demo data (accounts with a known password), which is not made in production.");
        }

        return $this->finish($request, 'db:seed', $this->commands->seed($name), "{$name} was run.", fn () => ['class' => $name]);
    }

    /**
     * The answer to a command that was run: busy, failed, or done.
     *
     * @param  array{busy: bool, exit_code: int|null, output: list<string>, error: ?string}  $result
     * @param  callable(): array<string, mixed>  $data  what to add to the answer when it is done (it must not need the database after migrate:fresh)
     */
    private function finish(Request $request, string $command, array $result, string $message, callable $data): JsonResponse
    {
        if ($result['busy']) {
            return $this->fail(409, 'ANOTHER_COMMAND_IS_RUNNING', 'Another command is running. Wait for it to finish.');
        }

        Log::info("{$command} run from the admin API", ['user_id' => $request->user()->id, 'exit_code' => $result['exit_code'], 'error' => $result['error']]);

        if ($result['error'] !== null) {
            return response()->json(['success' => false, 'message' => "{$command} failed: {$result['error']}", 'data' => ['code' => 'COMMAND_FAILED', 'exit_code' => $result['exit_code'], 'output' => $result['output']]], 500);
        }

        return $this->success(['output' => $result['output']] + $data(), $message);
    }

    private function fail(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => ['code' => $code]], $status);
    }
}
