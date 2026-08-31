@extends('admin.layout')

@section('title', __('admin.feature_definitions.edit_title'))
@section('page_title', __('admin.feature_definitions.edit_title'))

@section('content')
<div class="max-w-lg">
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @php
        $optionsText = collect($featureDefinition->options ?? [])->map(fn ($o) => $o['key'].'|'.$o['label'])->implode("\n");
    @endphp

    <form method="POST" action="{{ route('admin.feature-definitions.update', $featureDefinition) }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
        @csrf
        @method('PUT')

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.category') }}</label>
            <select name="category_id" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $featureDefinition->category_id) == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.label') }}</label>
            <input type="text" name="label" value="{{ old('label', $featureDefinition->label) }}" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
        </div>

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.key') }}</label>
            <input type="text" name="key" value="{{ old('key', $featureDefinition->key) }}" required pattern="[a-z0-9_]+" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs">
            <p class="text-xs text-slate-400 mt-1">{{ __('admin.feature_definitions.key_hint') }}</p>
        </div>

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.type') }}</label>
            <select name="type" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
                @foreach (['text', 'number', 'boolean', 'select', 'multi_select'] as $type)
                    <option value="{{ $type }}" @selected(old('type', $featureDefinition->type) === $type)>{{ __('admin.feature_definitions.types.'.$type) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.options') }}</label>
            <textarea name="options" rows="4" class="w-full border border-slate-200 rounded-lg px-3 py-2 font-mono text-xs">{{ old('options', $optionsText) }}</textarea>
            <p class="text-xs text-slate-400 mt-1">{{ __('admin.feature_definitions.options_hint') }}</p>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_required" value="1" @checked(old('is_required', $featureDefinition->is_required))>
            {{ __('admin.feature_definitions.required') }}
        </label>

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.sort_order') }}</label>
            <input type="number" name="sort_order" value="{{ old('sort_order', $featureDefinition->sort_order) }}" min="0" class="w-full border border-slate-200 rounded-lg px-3 py-2">
        </div>

        <div>
            <label class="block font-medium mb-1 text-slate-700">{{ __('admin.feature_definitions.status') }}</label>
            <select name="status" class="w-full border border-slate-200 rounded-lg px-3 py-2">
                <option value="active" @selected(old('status', $featureDefinition->status) === 'active')>{{ __('admin.feature_definitions.active') }}</option>
                <option value="inactive" @selected(old('status', $featureDefinition->status) === 'inactive')>{{ __('admin.feature_definitions.inactive') }}</option>
            </select>
        </div>

        <button type="submit" class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.feature_definitions.save_submit') }}</button>
    </form>
</div>
@endsection
