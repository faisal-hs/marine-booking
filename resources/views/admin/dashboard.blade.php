@extends('admin.layout')

@section('title', __('admin.dashboard.title'))
@section('page_title', __('admin.dashboard.title'))

@section('content')
    <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">{{ __('admin.dashboard.needs_attention') }}</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-[#FEF2F2] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['providers']['pending_verification'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.pending_verification') }}</p>
        </div>
        <div class="bg-[#FEF9E7] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['activities']['pending_review'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.pending_activities') }}</p>
        </div>
        <div class="bg-[#EFF6FF] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['payments']['pending_count'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.pending_payments') }}</p>
        </div>
        <div class="bg-[#FEF2F2] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['payments']['failed_recent_count'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.failed_payments_7d') }}</p>
        </div>
    </div>

    <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">{{ __('admin.dashboard.overview') }}</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-[#ECFDF5] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['users']['total'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.total_users') }}</p>
        </div>
        <div class="bg-[#ECFDF5] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['reservations']['today'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.reservations_today') }}</p>
        </div>
        <div class="bg-[#ECFDF5] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ number_format($stats['payments']['net_revenue'], 2) }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.net_revenue') }} (EGP)</p>
        </div>
        <div class="bg-[#ECFDF5] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['reviews']['average_platform_rating'] }} / 5</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.average_rating') }} ({{ $stats['reviews']['total'] }})</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.dashboard.recent_reservations') }}</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 font-medium">{{ __('admin.reservations.customer') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reservations.activity') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reservations.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentReservations as $reservation)
                        <tr class="border-b border-slate-50">
                            <td class="py-2.5">{{ $reservation->customer->user->name }}</td>
                            <td class="py-2.5 text-slate-500">{{ $reservation->activity->title }}</td>
                            <td class="py-2.5">
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
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-slate-400 text-center">{{ __('admin.reservations.no_reservations') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            @can('reservations.view')
                <a href="{{ route('admin.reservations.index') }}" class="inline-block mt-4 text-xs text-slate-500 underline">
                    {{ __('admin.dashboard.view_all') }} →
                </a>
            @endcan
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.dashboard.recent_payments') }}</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 font-medium">{{ __('admin.payments.reference') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reservations.total') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.reservations.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentPayments as $payment)
                        <tr class="border-b border-slate-50">
                            <td class="py-2.5 text-slate-500">{{ $payment->reference_id }}</td>
                            <td class="py-2.5">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</td>
                            <td class="py-2.5">
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
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-slate-400 text-center">{{ __('admin.payments.no_payments') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            @can('payments.view')
                <a href="{{ route('admin.payments.index') }}" class="inline-block mt-4 text-xs text-slate-500 underline">
                    {{ __('admin.dashboard.view_all') }} →
                </a>
            @endcan
        </div>

        <div class="sidebar-bg rounded-xl p-6 text-white">
            <h2 class="text-sm font-semibold mb-2">{{ __('admin.dashboard.verification_queue') }}</h2>
            <p class="text-3xl font-semibold text-teal-300">{{ $stats['providers']['pending_verification'] }}</p>
            <p class="text-xs text-slate-400 mt-1">{{ __('admin.dashboard.waiting_review') }}</p>
            @can('providers.verify')
                <a href="{{ route('admin.providers.index', ['status' => 'pending']) }}"
                    class="inline-block mt-4 text-xs bg-teal-500/20 text-teal-300 rounded px-3 py-2 hover:bg-teal-500/30 transition">
                    {{ __('admin.dashboard.review_providers') }} →
                </a>
            @endcan
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.dashboard.recent_users') }}</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-400 border-b border-slate-100">
                    <th class="py-2 font-medium">{{ __('admin.dashboard.name') }}</th>
                    <th class="py-2 font-medium">{{ __('admin.dashboard.type') }}</th>
                    <th class="py-2 font-medium">{{ __('admin.dashboard.joined') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentUsers as $user)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5">{{ $user->name }}</td>
                        <td class="py-2.5 text-slate-500">{{ ucfirst($user->user_type) }}</td>
                        <td class="py-2.5 text-slate-400">{{ $user->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-4 text-slate-400 text-center">{{ __('admin.dashboard.no_users') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
