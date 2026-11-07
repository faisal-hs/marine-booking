<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\ChangeActivityStatusRequest;
use App\Services\ProviderApp\ProviderActivityStatus;

class ActivityStatusController extends ProviderAppController
{
    public function __construct(private readonly ProviderActivityStatus $statuses) {}

    public function update(ChangeActivityStatusRequest $request, string $id)
    {
        $activity = $this->statuses->change($this->provider($request), $id, $request->target());

        return $this->ok(['id' => $activity->id, 'status' => ProviderActivityStatus::label($activity->status)], 'Activity status updated');
    }
}
