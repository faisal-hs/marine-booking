<?php

namespace App\Http\Requests\Provider\Activity;

use App\Http\Requests\PaginationRequest;

/** The provider's activities. `filter` is the tab: all, draft, active or paused (see ActivityRepository::STATUS_GROUPS). An empty value means all. */
class ListActivitiesRequest extends PaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'filter' => ['sometimes', 'nullable', 'string', 'in:all,draft,active,paused'],
            'status' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
