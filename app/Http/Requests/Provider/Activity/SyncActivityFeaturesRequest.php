<?php

namespace App\Http\Requests\Provider\Activity;

use App\Services\ActivityFeatureService;
use Illuminate\Foundation\Http\FormRequest;

class SyncActivityFeaturesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules are built dynamically from the target activity's category —
     * the actual rule-building logic lives in ActivityFeatureService,
     * not here, keeping business logic out of the Request class.
     */
    public function rules(): array
    {
        return app(ActivityFeatureService::class)->buildValidationRules($this->route('activity'));
    }
}
