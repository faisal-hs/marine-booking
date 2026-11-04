<?php

namespace App\Http\Requests\Web\Admin\Activity;

use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;

/** An activity created by staff on a provider's behalf. It starts as a DRAFT like any other. */
class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ActivityFieldRules::withDefaultMinimum($this->all()));
    }

    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                $provider = Provider::with('user')->find($value);

                if ($provider === null) {
                    $fail(__('admin.activities.provider_invalid'));
                } elseif ($provider->user->trashed()) {
                    $fail(__('admin.activities.provider_deleted'));
                } elseif ($provider->user->status !== 'active') {
                    $fail(__('admin.activities.provider_suspended'));
                }
            }],
            ...ActivityFieldRules::core(),
        ];
    }

    public function provider(): Provider
    {
        return Provider::findOrFail($this->integer('provider_id'));
    }

    public function payload(): array
    {
        return ActivityFieldRules::payload($this->safe()->except('provider_id'));
    }
}
