{{--
The core activity fields, shared by create and edit.
$activity is null when creating; $providers is only passed when creating (an activity's
provider is fixed once it exists).
--}}
@if (isset($providers))
    <div>
        <label for="provider_id" class="block font-medium mb-1 text-slate-700">
            {{ __('admin.activities.provider') }} <span class="text-red-500">*</span>
        </label>
        <select id="provider_id" name="provider_id" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
            <option value="">{{ __('admin.activities.choose_provider') }}</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}" @selected((string) old('provider_id') === (string) $provider->id)>{{ $provider->business_name }}</option>
            @endforeach
        </select>
        @error('provider_id')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@endif

<div>
    <label for="category_id" class="block font-medium mb-1 text-slate-700">
        {{ __('admin.activities.category') }} <span class="text-red-500">*</span>
    </label>
    <select id="category_id" name="category_id" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
        <option value="">{{ __('admin.activities.choose_category') }}</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((string) old('category_id', $activity?->category_id) === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
    @error('category_id')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
    @if ($activity)
        <p class="mt-1 text-xs text-amber-600">{{ __('admin.activities.category_warning') }}</p>
    @endif
</div>

<x-admin.field name="title" :label="__('admin.activities.title_field')" :value="$activity?->title" required />
<x-admin.field name="description" type="textarea" rows="5" :label="__('admin.activities.description_field')" :value="$activity?->description" />

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <x-admin.field name="location" :label="__('admin.activities.location')" :value="$activity?->location" />
    <x-admin.field name="meeting_point" :label="__('admin.activities.meeting_point')" :value="$activity?->meeting_point" />
</div>

<div class="grid grid-cols-2 gap-4">
    <x-admin.field name="lat" type="number" step="any" min="-90" max="90" :label="__('admin.providers.lat')" :value="$activity?->lat" />
    <x-admin.field name="lng" type="number" step="any" min="-180" max="180" :label="__('admin.providers.lng')" :value="$activity?->lng" />
</div>

<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <x-admin.field name="duration_minutes" type="number" min="1" :label="__('admin.activities.duration_minutes')" :value="$activity?->duration_minutes" />
    <x-admin.field name="min_participants" type="number" min="1" :label="__('admin.activities.min_participants')" :value="$activity?->min_participants ?? 1" />
    <x-admin.field name="max_participants" type="number" min="1" :label="__('admin.activities.max_participants')" :value="$activity?->max_participants" />
    <x-admin.field name="min_age" type="number" min="0" max="120" :label="__('admin.activities.min_age')" :value="$activity?->min_age" />
</div>
