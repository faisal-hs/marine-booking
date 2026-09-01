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
                            <th class="py-2 font-medium">{{ __('admin.activities.price') }}</th>
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
                                <td class="py-2.5 text-slate-500">{{ number_format((float) $period->price, 2) }}</td>
                                <td class="py-2.5">
                                    <x-admin.status-badge
                                        :variant="$period->status === 'active' ? 'success' : 'neutral'"
                                        :label="__('admin.activities.'.$period->status)" />
                                </td>
                            </tr>
                            @if ($period->instructions)
                                <tr class="border-b border-slate-50">
                                    <td colspan="4" class="pb-2.5 text-xs text-slate-400">{{ $period->instructions }}</td>
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
