<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ActivityFieldRules::withDefaultMinimum($this->all()));
    }

    public function rules(): array
    {
        return ActivityFieldRules::core();
    }

    public function payload(): array
    {
        return ActivityFieldRules::payload($this->safe()->all());
    }
}
