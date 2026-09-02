@extends('admin.layout')

@section('title', __('admin.reservations.title'))
@section('page_title', __('admin.reservations.title'))

@section('content')
    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
               placeholder="{{ __('admin.reservations.search_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">

        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.reservations.all_statuses') }}</option>
            @foreach (['pending', 'confirmed', 'cancelled', 'expired', 'completed'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.reservations.statuses.' . $status) }}
                </option>
            @endforeach
        </select>

        <input type="text" name="activity" value="{{ $filters['activity'] ?? '' }}"
               placeholder="{{ __('admin.reservations.activity_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5 w-40">

        <input type="text" name="booking_option" value="{{ $filters['booking_option'] ?? '' }}"
               placeholder="{{ __('admin.reservations.booking_option_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5 w-40">

        <input type="text" name="customer" value="{{ $filters['customer'] ?? '' }}"
               placeholder="{{ __('admin.reservations.customer_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5 w-40">

        <input type="date" name="date" value="{{ $filters['date'] ?? '' }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">

        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
               title="{{ __('admin.reservations.date_from') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
               title="{{ __('admin.reservations.date_to') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">

        <select name="sort" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>{{ __('admin.reservations.newest_first') }}</option>
            <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>{{ __('admin.reservations.oldest_first') }}</option>
        </select>

        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.reservations.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.id_column') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.customer') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.activity') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.booking_option') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.date') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.total') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($reservations as $reservation)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4 text-slate-500">#{{ $reservation->id }}</td>
                        <td class="py-2.5 px-4">{{ $reservation->customer->user->name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $reservation->activity->title }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $reservation->bookingOption->name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $reservation->reservation_date->toDateString() }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ number_format((float) $reservation->total, 2) }} {{ $reservation->currency }}</td>
                        <td class="py-2.5 px-4">
                            @php
                                $variant = match ($reservation->status->value) {
                                    'confirmed' => 'success',
                                    'pending' => 'warning',
                                    'cancelled', 'expired' => 'danger',
                                    default => 'info',
                                };
                            @endphp
                            <x-admin.status-badge :variant="$variant" :label="__('admin.reservations.statuses.' . $reservation->status->value)" />
                        </td>
                        <td class="py-2.5 px-4">
                            <a href="{{ route('admin.reservations.show', $reservation) }}" class="text-sm text-[var(--brand-accent,theme(colors.slate.700))] underline">
                                {{ __('admin.reservations.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-6 px-4 text-center text-slate-400">{{ __('admin.reservations.no_reservations') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $reservations->links() }}</div>
@endsection
