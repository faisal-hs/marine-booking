<?php

namespace App\Http\Requests\Admin\System;

use Illuminate\Foundation\Http\FormRequest;

/** Looking at the migrations, and running the pending ones: the permission system.migrate. */
class MigrationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.migrate');
    }

    public function rules(): array
    {
        return [];
    }
}
