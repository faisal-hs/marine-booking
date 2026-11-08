<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;

/** Why an admin is stopping an activity: the provider is told. */
class SuspendActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['suspension_reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
