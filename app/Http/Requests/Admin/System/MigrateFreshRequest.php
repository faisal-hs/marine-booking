<?php

namespace App\Http\Requests\Admin\System;

use App\Services\SystemCommandService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * migrate:fresh DROPS EVERY TABLE, so it asks for more than a permission (system.migrate_fresh): the phrase "migrate:fresh" typed out, and the acting admin's own password.
 * `seed` (with `seeder`, a name from config/system_commands.php, DatabaseSeeder by default) seeds afterwards: without it the database is empty and nobody can sign in.
 */
class MigrateFreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.migrate_fresh');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('seed') && is_string($this->input('seed'))) {
            $words = ['true' => true, 'false' => false, '1' => true, '0' => false];
            $this->merge(['seed' => $words[strtolower(trim($this->input('seed')))] ?? $this->input('seed')]);
        }
    }

    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', Rule::in([SystemCommandService::CONFIRM_PHRASE])],
            'password' => ['required', 'string'],
            'seed' => ['sometimes', 'boolean'],
            'seeder' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(app(SystemCommandService::class)->seeders()))],
        ];
    }

    public function messages(): array
    {
        return ['confirm.in' => 'To confirm, send confirm: "'.SystemCommandService::CONFIRM_PHRASE.'".'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('password') && ! Hash::check((string) $this->input('password'), (string) $this->user()->password)) {
                $validator->errors()->add('password', 'The password is not yours.');
            }
        }];
    }

    /** The seeder to run afterwards, or null for none. */
    public function seeder(): ?string
    {
        return $this->boolean('seed') ? ($this->input('seeder') ?: 'DatabaseSeeder') : null;
    }
}
