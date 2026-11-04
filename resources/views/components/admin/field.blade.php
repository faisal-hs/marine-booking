{{--
A labelled form field with its own validation message. Text-like inputs and textareas;
selects are written out by hand where needed.

Usage:
<x-admin.field name="business_name" :label="__('admin.providers.business_name')" :value="$provider->business_name ?? null" required />
<x-admin.field name="bio" type="textarea" :label="__('admin.providers.bio')" :value="$provider->bio ?? null" rows="4" />

The shown value is old($name) when the form is redisplayed after a validation error,
otherwise $value (the saved value when editing).
--}}
@props([
    'name',
    'label',
    'type' => 'text', // text | email | number | date | password | textarea
    'value' => null,
    'required' => false,
    'help' => null,
    'rows' => 3,
    'step' => null,
    'min' => null,
    'max' => null,
])

<div>
    <label for="{{ $name }}" class="block font-medium mb-1 text-slate-700">
        {{ $label }}@if ($required) <span class="text-red-500">*</span>@endif
    </label>

    @if ($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" @required($required)
            class="w-full border border-slate-200 rounded-lg px-3 py-2">{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $name }}" type="{{ $type }}" name="{{ $name }}"
            @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
            @required($required)
            @if ($step !== null) step="{{ $step }}" @endif
            @if ($min !== null) min="{{ $min }}" @endif
            @if ($max !== null) max="{{ $max }}" @endif
            class="w-full border border-slate-200 rounded-lg px-3 py-2">
    @endif

    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
    @if ($help)
        <p class="mt-1 text-xs text-slate-400">{{ $help }}</p>
    @endif
</div>
