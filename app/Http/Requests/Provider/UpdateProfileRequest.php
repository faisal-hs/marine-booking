<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** A form upload sends "true" and "false" as words. */
    protected function prepareForValidation(): void
    {
        if ($this->has('remove_image') && is_string($this->input('remove_image'))) {
            $this->merge(['remove_image' => filter_var($this->input('remove_image'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input('remove_image')]);
        }
    }

    /** Whether the request changes the picture (a new one, or taking it off). */
    public function changesImage(): bool
    {
        return $this->hasFile('image') || $this->boolean('remove_image');
    }

    public function rules(): array
    {
        $providerId = $this->user()->provider?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'sex' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::enum(\App\Enums\Sex::class)],
            'business_name' => ['sometimes', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:120'],
            'area' => ['sometimes', 'string', 'max:120'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],
            // The profile picture: an image (not an SVG, which can carry a script) up to 4 MB, or `remove_image: true` to take it off.
            'image' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean', 'prohibits:image'],
        ];
    }

    public function payload(): array
    {
        return $this->only(['name', 'business_name', 'bio', 'address', 'city', 'area', 'lat', 'lng', 'email', 'sex']);
    }
}
