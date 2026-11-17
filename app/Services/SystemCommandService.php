<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * `php artisan migrate`, `migrate:fresh` and `db:seed`, for the admin API (an installation with no shell). What it does, around the framework's own commands:
 *
 *   - ONE AT A TIME. A lock on a file (not in the database: migrate:fresh drops the tables a database lock would live in) means a second command answers "another command
 *     is running" instead of running into the first.
 *   - IT FINISHES. The request is not cut short by the time limit or by the client giving up: a migration stopped half-way is worse than one that is slow.
 *   - FAILURE IS AN ANSWER. An error (a migration that throws, a seeder that does) is returned with its message and what the command had printed, not thrown.
 *   - ONLY WHAT IS LISTED. Seeders come from config/system_commands.php: nothing else can be named.
 *
 * Who may use it, and the extra confirmation of the destructive command, is the controller's and the requests' business.
 */
class SystemCommandService
{
    /** What the admin must type to confirm migrate:fresh. */
    public const CONFIRM_PHRASE = 'migrate:fresh';

    /** @return array{environment: string, ran: int, pending: list<string>} */
    public function migrations(): array
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        $files = array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));

        return ['environment' => app()->environment(), 'ran' => count($ran), 'pending' => array_values(array_diff($files, $ran))];
    }

    /** @return array<string, string> class name => 'safe' or 'demo' */
    public function seeders(): array
    {
        $kinds = [];

        foreach (['safe', 'demo'] as $kind) {
            foreach (config("system_commands.seeders.{$kind}", []) as $name) {
                $kinds[$name] = $kind;
            }
        }

        return $kinds;
    }

    /** Whether a seeder may be run here: a safe one anywhere, a demo one anywhere but production. */
    public function seederAllowedHere(string $name): bool
    {
        $kind = $this->seeders()[$name] ?? null;

        return $kind === 'safe' || ($kind === 'demo' && ! app()->isProduction());
    }

    public function migrateFreshAllowedHere(): bool
    {
        return ! app()->isProduction() || (bool) config('system_commands.allow_migrate_fresh_in_production');
    }

    /** @return array{busy: bool, exit_code: int|null, output: list<string>, error: ?string} */
    public function migrate(): array
    {
        return $this->run('migrate', ['--force' => true]);
    }

    /** Drops every table and migrates again, and seeds with `$seeder` (a name from the list) when asked. @return array{busy: bool, exit_code: int|null, output: list<string>, error: ?string} */
    public function fresh(?string $seeder = null): array
    {
        return $this->run('migrate:fresh', ['--force' => true] + ($seeder ? ['--seed' => true, '--seeder' => $this->class($seeder)] : []));
    }

    /** @return array{busy: bool, exit_code: int|null, output: list<string>, error: ?string} */
    public function seed(string $seeder): array
    {
        return $this->run('db:seed', ['--class' => $this->class($seeder), '--force' => true]);
    }

    private function class(string $name): string
    {
        return 'Database\\Seeders\\'.$name;
    }

    /** @param  array<string, mixed>  $arguments */
    private function run(string $command, array $arguments): array
    {
        $lock = $this->lock();

        if ($lock === null) {
            return ['busy' => true, 'exit_code' => null, 'output' => [], 'error' => null];
        }

        // Not to be cut short: a migration stopped half-way is worse than a slow one.
        @set_time_limit(0);
        ignore_user_abort(true);
        $exit = null;
        $error = null;
        $output = [];

        try {
            $exit = Artisan::call($command, $arguments);
            $output = $this->lines(Artisan::output());
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $output = $this->lines(Artisan::output());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return ['busy' => false, 'exit_code' => $exit, 'output' => $output, 'error' => $error ?? ($exit ? "The command ended with the exit code {$exit}." : null)];
    }

    /** @return resource|null the held lock, or null when another command is running */
    private function lock()
    {
        $path = storage_path('framework/system-command.lock');
        @mkdir(dirname($path), 0775, true);
        $handle = fopen($path, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            $handle && fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', preg_replace('/\e\[[0-9;]*m/', '', $output)) ?: []), fn ($line) => $line !== ''));
    }
}
