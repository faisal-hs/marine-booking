<?php

namespace App\Http\Requests\CustomerApp;

use App\Services\CustomerApp\ActivityCatalog;
use Illuminate\Validation\Rule;

/** A provider's activities, a page at a time: the same `sort` as the activity list, and optionally one category. */
class ListProviderActivitiesRequest extends PageRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'nullable', Rule::in(ActivityCatalog::SORTS)],
        ];
    }
}
