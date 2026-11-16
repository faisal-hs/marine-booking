<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Admin\Storage\StorageLinkRequest;
use App\Services\StorageLinkService;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan storage:link` as an API: what state the public/storage link is in, and making it. Super admin only (see StorageLinkRequest); every run is logged.
 */
class StorageLinkController extends BaseApiController
{
    public function __construct(private readonly StorageLinkService $links) {}

    /** The state of each link, without changing anything. */
    public function show(StorageLinkRequest $request)
    {
        $links = $this->links->status();

        return $this->success(['linked' => collect($links)->every(fn ($l) => $l['state'] === 'linked'), 'links' => $links], 'Storage link status.');
    }

    /** Runs the command. Safe to repeat: a link that is already right is left alone. */
    public function store(StorageLinkRequest $request)
    {
        $result = $this->links->run($request->boolean('force'));

        Log::info('storage:link run from the admin API', ['user_id' => $request->user()->id, 'force' => $request->boolean('force'), 'created' => $result['created'], 'replaced' => $result['replaced'], 'ok' => $result['ok']]);

        $data = ['linked' => $result['ok'], 'created' => $result['created'], 'replaced' => $result['replaced'], 'links' => $result['links'], 'output' => $result['output']];

        if ($result['error'] !== null) {
            return response()->json(['success' => false, 'message' => 'The storage link could not be created on this server: '.$result['error'], 'data' => $data], 500);
        }

        if (! $result['ok']) {
            // The command ran, but a link is still not right: a real file is in the way, or a link points elsewhere and `force` was not asked for.
            return response()->json(['success' => false, 'message' => 'The storage link is not in place: '.$this->why($result['links']), 'data' => $data], 409);
        }

        return $this->success($data, $result['created'] || $result['replaced'] ? 'Storage link created.' : 'The storage link was already in place.');
    }

    /** @param  list<array{link: string, state: string, target_exists: bool}>  $links */
    private function why(array $links): string
    {
        $reasons = ['blocked' => 'a real file or folder is in the way (it is never replaced)', 'wrong_target' => 'a link points somewhere else (send force to replace it)',
            'broken' => 'a link exists but its target is gone', 'missing' => 'it could not be created'];

        return collect($links)->reject(fn ($l) => $l['state'] === 'linked')
            // A link to a folder that does not exist is not cured by replacing it: the folder is what is missing.
            ->map(fn ($l) => "{$l['link']}: ".($l['state'] === 'broken' && ! $l['target_exists'] ? "its target folder ({$l['target']}) does not exist" : $reasons[$l['state']]))->implode('; ');
    }
}
