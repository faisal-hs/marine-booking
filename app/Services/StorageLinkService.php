<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * `php artisan storage:link`, from the admin API: the public/storage link that makes the files in storage/app/public (provider pictures, activity photos) reachable
 * from the web. It works on the links the app is configured with (config 'filesystems.links'), by the framework's own command, so it does exactly what the command does:
 * it never touches a real directory or file that is where a link should be (that one is "blocked"), and it replaces a link that points at a valid but different place only
 * when asked to (`force`).
 *
 * Each link is in one of these states:
 *   linked        the link exists and points at its target: nothing to do
 *   missing       there is nothing at the link's place: the command makes it
 *   wrong_target  a link exists but points somewhere else: left alone unless `force` is asked for
 *   broken        a link exists but what it points at is gone: the command itself replaces it (a dangling link does not count as existing); if the TARGET folder
 *                 is what is missing, it stays broken and the folder has to be made
 *   blocked       a real file or directory is where the link should be: never touched
 *
 * The paths are given from the application's base folder, never as absolute server paths.
 */
class StorageLinkService
{
    /** @return list<array{link: string, target: string, state: string, target_exists: bool}> */
    public function status(): array
    {
        $rows = [];

        foreach ($this->links() as $link => $target) {
            $rows[] = ['link' => $this->relative($link), 'target' => $this->relative($target), 'state' => $this->state($link, $target), 'target_exists' => is_dir($target)];
        }

        return $rows;
    }

    /**
     * Runs the command, and says what it did and what the state is now.
     *
     * @return array{ran: bool, exit_code: int|null, output: list<string>, error: ?string, before: list<array>, links: list<array>, created: list<string>, replaced: list<string>, ok: bool}
     */
    public function run(bool $force = false): array
    {
        $before = $this->status();
        $exit = null;
        $error = null;
        $output = [];

        try {
            $exit = Artisan::call('storage:link', $force ? ['--force' => true] : []);
            $output = array_values(array_filter(array_map('trim', preg_split('/\R/', Artisan::output()) ?: [])));
        } catch (Throwable $e) {
            // The host may not allow links at all (symlink() disabled, or no right to write there): that is reported, not thrown.
            $error = $e->getMessage();
        }

        $after = $this->status();
        $was = array_column($before, 'state', 'link');
        $created = [];
        $replaced = [];

        foreach ($after as $row) {
            if ($row['state'] === 'linked' && ($was[$row['link']] ?? null) === 'missing') {
                $created[] = $row['link'];
            } elseif ($row['state'] === 'linked' && in_array($was[$row['link']] ?? null, ['wrong_target', 'broken'], true)) {
                $replaced[] = $row['link'];
            }
        }

        return ['ran' => true, 'exit_code' => $exit, 'output' => $output, 'error' => $error, 'before' => $before, 'links' => $after, 'created' => $created, 'replaced' => $replaced,
            'ok' => $error === null && collect($after)->every(fn ($row) => $row['state'] === 'linked')];
    }

    /** @return array<string, string> link => target, as the framework's command reads them */
    private function links(): array
    {
        return config('filesystems.links') ?? [public_path('storage') => storage_path('app/public')];
    }

    private function state(string $link, string $target): string
    {
        if (is_link($link)) {
            $resolved = realpath($link);

            if ($resolved === false) {
                return 'broken';
            }

            return $resolved === realpath($target) ? 'linked' : 'wrong_target';
        }

        return file_exists($link) ? 'blocked' : 'missing';
    }

    /** A path from the application's base folder, so the answer never gives away where on the server the application lives. */
    private function relative(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }
}
