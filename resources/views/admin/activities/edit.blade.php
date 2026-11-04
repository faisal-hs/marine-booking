@extends('admin.layout')

@section('title', __('admin.activities.edit_title'))
@section('page_title', $activity->title)

@section('content')
    @php
        $statusVariant = match ($activity->status) {
            'published' => 'success',
            'pending_review' => 'warning',
            'rejected' => 'danger',
            'inactive' => 'neutral',
            default => 'info',
        };
    @endphp

    <div class="max-w-3xl space-y-6">
        <x-admin.alert variant="danger" :errors="$errors" />

        <div class="flex items-center justify-between text-sm">
            <div class="flex items-center gap-3">
                <x-admin.status-badge :variant="$statusVariant" :label="__('admin.activities.statuses.' . $activity->status)" />
                <span class="text-slate-500">{{ __('admin.activities.provider') }}: {{ $activity->provider->business_name }}</span>
            </div>
            <div class="flex items-center gap-2">
                <x-admin.button pill href="{{ route('admin.activities.show', $activity) }}">{{ __('admin.activities.view') }}</x-admin.button>
                @can('adminDelete', $activity)
                    <x-admin.confirm-form :action="route('admin.activities.destroy', $activity)" method="DELETE" :confirm="__('admin.activities.confirm_delete')" variant="danger" icon="trash">{{ __('admin.activities.delete') }}</x-admin.confirm-form>
                @endcan
            </div>
        </div>

        <p class="text-xs text-slate-400">{{ __('admin.activities.staff_edit_note') }}</p>

        {{-- Details --}}
        <form method="POST" action="{{ route('admin.activities.update', $activity) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')
            <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.activities.details_title') }}</h2>

            @include('admin.activities._form', ['activity' => $activity])

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.activities.save_submit') }}</button>
        </form>

        {{-- Features: the attributes defined for this activity's category --}}
        <form id="features" method="POST" action="{{ route('admin.activities.features', $activity) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')
            <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.activities.features_title') }}</h2>

            @forelse ($definitions as $definition)
                @php
                    $current = old('features.' . $definition->key, $featureValues[$definition->key] ?? null);
                @endphp
                <div>
                    <label class="block font-medium mb-1 text-slate-700">
                        {{ $definition->label }}@if ($definition->is_required) <span class="text-red-500">*</span>@endif
                    </label>

                    @switch($definition->type)
                        @case('boolean')
                            {{-- The hidden 0 is what an unticked box submits; the box overrides it. --}}
                            <input type="hidden" name="features[{{ $definition->key }}]" value="0">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="features[{{ $definition->key }}]" value="1" @checked((bool) $current)>
                                <span class="text-slate-500">{{ __('admin.activities.yes') }}</span>
                            </label>
                            @break

                        @case('number')
                            <input type="number" step="any" name="features[{{ $definition->key }}]" value="{{ $current }}"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2">
                            @break

                        @case('select')
                            <select name="features[{{ $definition->key }}]" class="w-full border border-slate-200 rounded-lg px-3 py-2">
                                <option value="">—</option>
                                @foreach ($definition->options ?? [] as $option)
                                    <option value="{{ $option['key'] }}" @selected((string) $current === (string) $option['key'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            @break

                        @case('multi_select')
                            <div class="flex flex-wrap gap-4">
                                @foreach ($definition->options ?? [] as $option)
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="features[{{ $definition->key }}][]" value="{{ $option['key'] }}"
                                            @checked(in_array($option['key'], (array) $current, true))>
                                        <span class="text-slate-600">{{ $option['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @break

                        @default
                            <input type="text" name="features[{{ $definition->key }}]" value="{{ $current }}"
                                class="w-full border border-slate-200 rounded-lg px-3 py-2">
                    @endswitch

                    @error('features.' . $definition->key)
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @empty
                <p class="text-slate-400">{{ __('admin.activities.no_features') }}</p>
            @endforelse

            @if ($definitions->isNotEmpty())
                <button type="submit" class="bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.activities.save_section') }}</button>
            @endif
        </form>

        {{-- Requirements --}}
        @php
            $requirementRows = old('requirements', $activity->requirements->map(fn ($r) => ['text' => $r->text, 'is_mandatory' => $r->is_mandatory])->all());
            $requirementRows = array_pad(array_values($requirementRows), count($requirementRows) + 3, ['text' => '', 'is_mandatory' => false]);
        @endphp
        <form id="requirements" method="POST" action="{{ route('admin.activities.requirements', $activity) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
            @csrf
            @method('PUT')
            <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.activities.requirements_title') }}</h2>
            <p class="text-xs text-slate-400">{{ __('admin.activities.rows_help') }}</p>

            @foreach ($requirementRows as $i => $row)
                <div class="flex items-center gap-3">
                    <input type="text" name="requirements[{{ $i }}][text]" value="{{ $row['text'] ?? '' }}" maxlength="255"
                        placeholder="{{ __('admin.activities.requirement_text') }}"
                        class="flex-1 border border-slate-200 rounded-lg px-3 py-2">
                    <input type="hidden" name="requirements[{{ $i }}][is_mandatory]" value="0">
                    <label class="inline-flex items-center gap-1 text-slate-500">
                        <input type="checkbox" name="requirements[{{ $i }}][is_mandatory]" value="1" @checked(! empty($row['is_mandatory']))>
                        {{ __('admin.activities.mandatory') }}
                    </label>
                </div>
            @endforeach
            @error('requirements.*')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit" class="bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.activities.save_section') }}</button>
        </form>

        {{-- Inclusions and exclusions --}}
        @php
            $inclusionRows = old('inclusions', $activity->inclusions->map(fn ($i) => ['type' => $i->type, 'description' => $i->description])->all());
            $inclusionRows = array_pad(array_values($inclusionRows), count($inclusionRows) + 3, ['type' => 'included', 'description' => '']);
        @endphp
        <form id="inclusions" method="POST" action="{{ route('admin.activities.inclusions', $activity) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
            @csrf
            @method('PUT')
            <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.activities.inclusions_title') }}</h2>
            <p class="text-xs text-slate-400">{{ __('admin.activities.rows_help') }}</p>

            @foreach ($inclusionRows as $i => $row)
                <div class="flex items-center gap-3">
                    <select name="inclusions[{{ $i }}][type]" class="border border-slate-200 rounded-lg px-3 py-2">
                        <option value="included" @selected(($row['type'] ?? 'included') === 'included')>{{ __('admin.activities.included') }}</option>
                        <option value="excluded" @selected(($row['type'] ?? '') === 'excluded')>{{ __('admin.activities.excluded') }}</option>
                    </select>
                    <input type="text" name="inclusions[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" maxlength="255"
                        placeholder="{{ __('admin.activities.inclusion_text') }}"
                        class="flex-1 border border-slate-200 rounded-lg px-3 py-2">
                </div>
            @endforeach
            @error('inclusions.*')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit" class="bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.activities.save_section') }}</button>
        </form>

        {{-- Photos --}}
        <div id="photos" class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.activities.photos_title') }}</h2>

            <form method="POST" action="{{ route('admin.activities.images.store', $activity) }}" enctype="multipart/form-data" class="flex items-center gap-3">
                @csrf
                <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" required class="text-sm">
                <button type="submit" class="bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.activities.upload_photos') }}</button>
            </form>
            <p class="text-xs text-slate-400">{{ __('admin.activities.photos_help') }}</p>
            @error('images')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror
            @error('images.*')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            @if ($activity->images->isEmpty())
                <p class="text-slate-400">{{ __('admin.activities.no_photos') }}</p>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    @foreach ($activity->images as $image)
                        <div class="border border-slate-100 rounded-lg p-2 space-y-2">
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" alt="" class="w-full h-28 object-cover rounded">
                            <div class="flex items-center justify-between gap-2">
                                @if ($image->is_cover)
                                    <x-admin.status-badge variant="success" :label="__('admin.activities.cover')" />
                                @else
                                    <x-admin.confirm-form :action="route('admin.activities.images.cover', [$activity, $image])" method="PUT" variant="info">{{ __('admin.activities.make_cover') }}</x-admin.confirm-form>
                                @endif
                                <x-admin.confirm-form :action="route('admin.activities.images.destroy', [$activity, $image])" method="DELETE" :confirm="__('admin.activities.confirm_delete_photo')" variant="danger" icon="trash">{{ __('admin.activities.delete_photo') }}</x-admin.confirm-form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
