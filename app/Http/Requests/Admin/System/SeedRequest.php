<?php

namespace App\Http\Requests\Admin\System;

use App\Services\SystemCommandService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Running one seeder from the list in config/system_commands.php (and nothing else can be named): the permission system.seed. */
class SeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.seed');
    }

    public function rules(): array
    {
        return ['class' => ['required', 'string', Rule::in(array_keys(app(SystemCommandService::class)->seeders()))]];
    }

    public function messages(): array
    {
        return ['class.in' => 'That seeder is not one the API runs. GET /admin/system/seeders lists them.'];
    }
}
