<?php

namespace App\Http\Requests\Web\Admin\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('providers.update');
    }

    public function rules(): array
    {
        $ownerId = $this->route('provider')->user_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($ownerId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ownerId)],
            'business_name' => ['required', 'string', 'max:255'],
            'business_license' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            // How the platform's commission on this provider's bookings is worked out, the same for all their activities.
            'commission_type' => ['nullable', 'in:percentage,fixed'],
            // A percentage is 0 to 100; a fixed commission is an amount in the platform currency, kept once per booking.
            'commission_rate' => ['nullable', 'numeric', 'min:0', \Illuminate\Validation\Rule::when($this->input('commission_type', 'percentage') === 'percentage', ['max:100'])],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->all();
    }
}
