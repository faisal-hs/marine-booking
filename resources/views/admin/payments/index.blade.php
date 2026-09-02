@extends('admin.layout')

@section('title', __('admin.payments.title'))
@section('page_title', __('admin.payments.title'))

@section('content')
    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
               placeholder="{{ __('admin.payments.search_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">

        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.payments.all_statuses') }}</option>
            @foreach (['pending', 'succeeded', 'failed', 'refunded', 'partially_refunded'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.payments.statuses.' . $status) }}
                </option>
            @endforeach
        </select>

        <input type="text" name="gateway" value="{{ $filters['gateway'] ?? '' }}"
               placeholder="{{ __('admin.payments.gateway_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5 w-32">

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
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.payments.reference') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.customer') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.activity') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.payments.gateway') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.total') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4 text-slate-500">{{ $payment->reference_id }}</td>
                        <td class="py-2.5 px-4">{{ $payment->reservation->customer->user->name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $payment->reservation->activity->title }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $payment->gateway }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</td>
                        <td class="py-2.5 px-4">
                            @php
                                $variant = match ($payment->status->value) {
                                    'succeeded' => 'success',
                                    'pending' => 'warning',
                                    'failed' => 'danger',
                                    default => 'info',
                                };
                            @endphp
                            <x-admin.status-badge :variant="$variant" :label="__('admin.payments.statuses.' . $payment->status->value)" />
                        </td>
                        <td class="py-2.5 px-4">
                            <a href="{{ route('admin.payments.show', $payment) }}" class="text-sm text-[var(--brand-accent,theme(colors.slate.700))] underline">
                                {{ __('admin.reservations.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 px-4 text-center text-slate-400">{{ __('admin.payments.no_payments') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $payments->links() }}</div>
@endsection
