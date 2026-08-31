<?php

namespace App\Http\Requests\Web\Admin\FeatureDefinition;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeatureDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('feature_definitions.create');
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'label' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'type' => ['required', Rule::in(['text', 'number', 'boolean', 'select', 'multi_select'])],
            'options' => ['required_if:type,select,multi_select', 'nullable', 'string'],
            'is_required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function payload(): array
    {
        return [
            'category_id' => (int) $this->input('category_id'),
            'label' => $this->input('label'),
            'key' => $this->input('key'),
            'type' => $this->input('type'),
            'options_raw' => $this->input('options'),
            'is_required' => $this->boolean('is_required'),
            'sort_order' => (int) $this->input('sort_order', 0),
            'status' => $this->input('status'),
        ];
    }
}
