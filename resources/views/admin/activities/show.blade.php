@extends('admin.layout')

@section('title', $activity->title)
@section('page_title', $activity->title)

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.activities.schedule_title') }}</h2>

            @if ($activity->schedulePeriods->isEmpty())
                <p class="text-sm text-slate-400">{{ __('admin.activities.no_schedule') }}</p>
            @else
                @php
                    $weekdayKeys = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
                @endphp
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100">
                            <th class="py-2 font-medium">{{ __('admin.activities.day') }}</th>
                            <th class="py-2 font-medium">{{ __('admin.activities.time') }}</th>
                            <th class="py-2 font-medium">{{ __('admin.activities.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activity->schedulePeriods as $period)
                            <tr class="border-b border-slate-50">
                                <td class="py-2.5">{{ __('admin.activities.weekdays.'.$weekdayKeys[$period->day_of_week]) }}</td>
                                <td class="py-2.5 text-slate-500">
                                    {{ \Illuminate\Support\Carbon::parse($period->start_time)->format('H:i') }}
                                    →
                                    {{ \Illuminate\Support\Carbon::parse($period->end_time)->format('H:i') }}
                                    @if ($period->crosses_midnight)
                                        <span class="text-xs text-slate-400">({{ __('admin.activities.next_day') }})</span>
                                    @endif
                                </td>
                                <td class="py-2.5">
                                    <x-admin.status-badge
                                        :variant="$period->status === 'active' ? 'success' : 'neutral'"
                                        :label="__('admin.activities.'.$period->status)" />
                                </td>
                            </tr>
                            @if ($period->instructions)
                                <tr class="border-b border-slate-50">
                                    <td colspan="3" class="pb-2.5 text-xs text-slate-400">{{ $period->instructions }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.activities.overrides_title') }}</h2>

            @if ($activity->availabilityOverrides->isEmpty())
                <p class="text-sm text-slate-400">{{ __('admin.activities.no_overrides') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100">
                            <th class="py-2 font-medium">{{ __('admin.activities.date_range') }}</th>
                            <th class="py-2 font-medium">{{ __('admin.activities.status') }}</th>
                            <th class="py-2 font-medium">{{ __('admin.activities.price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activity->availabilityOverrides as $override)
                            <tr class="border-b border-slate-50">
                                <td class="py-2.5">{{ $override->date_from->toDateString() }} → {{ $override->date_to->toDateString() }}</td>
                                <td class="py-2.5">
                                    <x-admin.status-badge
                                        :variant="$override->is_unavailable ? 'danger' : 'info'"
                                        :label="$override->is_unavailable ? __('admin.activities.blackout') : __('admin.activities.price_override')" />
                                </td>
                                <td class="py-2.5 text-slate-500">{{ $override->price ? number_format((float) $override->price, 2) : __('admin.activities.inherited') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.activities.booking_options_title') }}</h2>

            @if ($activity->bookingOptions->isEmpty())
                <p class="text-sm text-slate-400">{{ __('admin.activities.no_booking_options') }}</p>
            @else
                <div class="space-y-5">
                    @foreach ($activity->bookingOptions as $option)
                        <div class="border border-slate-100 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-slate-800">{{ $option->name }}</span>
                                    <span class="text-xs text-slate-400">({{ $option->booking_model->value }})</span>
                                    @if ($option->is_default)
                                        <x-admin.status-badge variant="info" :label="__('admin.activities.default')" />
                                    @endif
                                </div>
                                <x-admin.status-badge
                                    :variant="$option->is_active ? 'success' : 'neutral'"
                                    :label="__('admin.activities.'.($option->is_active ? 'active' : 'inactive'))" />
                            </div>

                            @if ($option->description)
                                <p class="text-xs text-slate-400 mb-2">{{ $option->description }}</p>
                            @endif

                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 mb-3">
                                @if ($option->min_participants || $option->max_participants)
                                    <span>{{ __('admin.activities.capacity') }}: {{ $option->min_participants ?? '—' }}–{{ $option->max_participants ?? '—' }}</span>
                                @endif
                                @if ($option->min_quantity || $option->max_quantity)
                                    <span>{{ __('admin.activities.quantity_range') }}: {{ $option->min_quantity ?? '—' }}–{{ $option->max_quantity ?? '—' }}</span>
                                @endif
                                @if ($option->min_duration || $option->max_duration)
                                    <span>{{ __('admin.activities.duration_range') }}: {{ $option->min_duration ?? '—' }}–{{ $option->max_duration ?? '—' }} {{ $option->duration_unit }}</span>
                                @endif
                            </div>

                            @if ($option->pricingRules->isEmpty())
                                <p class="text-xs text-slate-400">{{ __('admin.activities.no_pricing_rules') }}</p>
                            @else
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="text-slate-400 border-b border-slate-100">
                                            <th class="py-1.5 text-left font-medium">{{ __('admin.activities.participant_type') }}</th>
                                            <th class="py-1.5 text-left font-medium">{{ __('admin.activities.applies_to') }}</th>
                                            <th class="py-1.5 text-left font-medium">{{ __('admin.activities.price') }}</th>
                                            <th class="py-1.5 text-left font-medium">{{ __('admin.activities.status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($option->pricingRules as $rule)
                                            <tr class="border-b border-slate-50">
                                                <td class="py-1.5">{{ $rule->participant_type?->value ?? __('admin.activities.general') }}</td>
                                                <td class="py-1.5 text-slate-500">
                                                    @if ($rule->schedulePeriod)
                                                        {{ __('admin.activities.weekdays.'.$weekdayKeys[$rule->schedulePeriod->day_of_week]) }}
                                                        {{ \Illuminate\Support\Carbon::parse($rule->schedulePeriod->start_time)->format('H:i') }}–{{ \Illuminate\Support\Carbon::parse($rule->schedulePeriod->end_time)->format('H:i') }}
                                                    @else
                                                        {{ __('admin.activities.general') }}
                                                    @endif
                                                </td>
                                                <td class="py-1.5 text-slate-500">{{ number_format((float) $rule->price, 2) }} {{ $rule->currency }}</td>
                                                <td class="py-1.5">
                                                    <x-admin.status-badge
                                                        :variant="$rule->is_active ? 'success' : 'neutral'"
                                                        :label="__('admin.activities.'.($rule->is_active ? 'active' : 'inactive'))" />
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6 h-fit">
        <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.activities.details_title') }}</h2>
        <dl class="text-sm space-y-2">
            <div><dt class="text-slate-400">{{ __('admin.activities.provider') }}</dt><dd>{{ $activity->provider->business_name }}</dd></div>
            <div><dt class="text-slate-400">{{ __('admin.activities.category') }}</dt><dd>{{ $activity->category->name }}</dd></div>
            <div><dt class="text-slate-400">{{ __('admin.activities.status') }}</dt><dd>{{ __('admin.activities.statuses.'.$activity->status) }}</dd></div>
        </dl>
    </div>
</div>
@endsection
