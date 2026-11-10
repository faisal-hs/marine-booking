@extends('admin.layout')

@section('title', __('admin.cities.title'))
@section('page_title', $city ? __('admin.cities.edit_title') : __('admin.cities.add_title'))

@section('content')
    <form method="POST" action="{{ $city ? route('admin.cities.update', $city) : route('admin.cities.store') }}" enctype="multipart/form-data"
        class="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl space-y-4">
        @csrf
        @if ($city) @method('PUT') @endif

        <x-admin.field name="name_en" :label="__('admin.cities.name_en')" :value="old('name_en', $city?->name_en)" required />
        <x-admin.field name="name_ar" :label="__('admin.cities.name_ar')" :value="old('name_ar', $city?->name_ar)" required />
        <x-admin.field name="slug" :label="__('admin.cities.slug')" :value="old('slug', $city?->slug)" />
        <p class="text-xs text-slate-400 -mt-2">{{ __('admin.cities.slug_hint') }}</p>
        <x-admin.field name="sort_order" type="number" min="0" :label="__('admin.cities.order')" :value="old('sort_order', $city?->sort_order ?? 0)" />

        <div>
            <label class="block text-sm text-slate-600 mb-1" for="image">{{ __('admin.cities.image') }}</label>
            @if ($city?->image)
                <img src="{{ Storage::disk('public')->url($city->image) }}" alt="" class="h-16 rounded mb-2">
            @endif
            <input id="image" type="file" name="image" accept="image/png,image/jpeg,image/webp" class="text-sm">
            @error('image') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $city?->is_active ?? true))>
            {{ __('admin.cities.active') }}
        </label>

        <div class="flex gap-2">
            <button type="submit" class="bg-slate-800 text-white rounded-lg px-4 py-2 text-sm">{{ __('admin.cities.save') }}</button>
            <a href="{{ route('admin.cities.index') }}" class="px-4 py-2 text-sm text-slate-500">{{ __('admin.cities.cancel') }}</a>
        </div>
    </form>
@endsection
