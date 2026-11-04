<?php

namespace App\Http\Requests\Web\Admin\User;

use App\Services\Admin\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.update');
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($userId)],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(app(UserManagementService::class)->assignableRoles($this->user()))],
        ];
    }

    public function payload(): array
    {
        return $this->only([
            'name',
            'email',
            'phone',
            'status',
            'role',
        ]);
    }
}
