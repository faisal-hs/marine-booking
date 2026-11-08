@extends('admin.layout')

@section('title', __('admin.document_types.title'))
@section('page_title', $type ? __('admin.document_types.edit_title') : __('admin.document_types.add_title'))

@section('content')
    <form method="POST" action="{{ $type ? route('admin.document-types.update', $type) : route('admin.document-types.store') }}"
        class="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
        @csrf
        @if ($type) @method('PUT') @endif

        @if ($type)
            <div>
                <p class="text-sm text-slate-400">{{ __('admin.document_types.key') }}</p>
                <p class="font-mono text-sm" dir="ltr">{{ $type->key }}</p>
                <p class="text-xs text-slate-400">{{ __('admin.document_types.key_fixed') }}</p>
            </div>
        @else
            <x-admin.field name="key" :label="__('admin.document_types.key')" :value="old('key')" required />
            <p class="text-xs text-slate-400 -mt-2">{{ __('admin.document_types.key_hint') }}</p>
        @endif

        <x-admin.field name="title_en" :label="__('admin.document_types.title_en')" :value="old('title_en', $type?->title_en)" required />
        <x-admin.field name="title_ar" :label="__('admin.document_types.title_ar')" :value="old('title_ar', $type?->title_ar)" required />
        <x-admin.field name="description_en" :label="__('admin.document_types.description_en')" :value="old('description_en', $type?->description_en)" />
        <x-admin.field name="description_ar" :label="__('admin.document_types.description_ar')" :value="old('description_ar', $type?->description_ar)" />
        <x-admin.field name="sort_order" type="number" min="0" :label="__('admin.document_types.order')" :value="old('sort_order', $type?->sort_order ?? 0)" />

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_mandatory" value="1" @checked(old('is_mandatory', $type?->is_mandatory ?? false))>
            {{ __('admin.document_types.mandatory') }}
            <span class="text-xs text-slate-400">{{ __('admin.document_types.mandatory_note') }}</span>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $type?->is_active ?? true))>
            {{ __('admin.document_types.active') }}
        </label>

        <div class="flex gap-2">
            <button type="submit" class="bg-slate-800 text-white rounded-lg px-4 py-2 text-sm">{{ __('admin.document_types.save') }}</button>
            <a href="{{ route('admin.document-types.index') }}" class="px-4 py-2 text-sm text-slate-500">{{ __('admin.document_types.cancel') }}</a>
        </div>
    </form>
@endsection
