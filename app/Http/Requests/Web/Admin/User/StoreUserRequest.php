<?php

namespace App\Http\Requests\Web\Admin\User;

use App\Services\Admin\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Creates a STAFF account; providers and customers are created in their own modules. */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            // Only roles the creator may hand out (a super admin is handed out by a super admin).
            'role' => ['required', 'string', Rule::in(app(UserManagementService::class)->assignableRoles($this->user()))],
            'job_title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function payload(): array
    {
        return $this->only(['name', 'email', 'phone', 'password', 'role', 'job_title']);
    }
}
