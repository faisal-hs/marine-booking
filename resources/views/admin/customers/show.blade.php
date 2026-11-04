@extends('admin.layout')

@section('title', __('admin.customers.details_title'))
@section('page_title', $customer->user->name)

@section('content')
    @php
        $deleted = $customer->user->trashed();
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.customers.details_title') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.dashboard.name') }}</dt>
                        <dd><x-admin.account-name :user="$customer->user" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.phone') }}</dt>
                        <dd>{{ $customer->user->phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.email') }}</dt>
                        <dd>{{ $customer->user->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.customers.date_of_birth') }}</dt>
                        <dd>{{ $customer->date_of_birth?->toFormattedDateString() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.customers.language') }}</dt>
                        <dd>{{ strtoupper($customer->preferred_language) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.customers.summary_title') }}</h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ $summary['bookings_total'] }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.customers.bookings') }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ $summary['bookings_live'] }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.customers.bookings_live') }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ number_format($summary['total_paid'], 2) }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.customers.total_paid') }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-semibold text-slate-800">{{ $summary['reviews'] }} / {{ $summary['favorites'] }}</p>
                        <p class="text-xs text-slate-500">{{ __('admin.customers.reviews_favorites') }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.customers.recent_bookings') }}</h2>

                @forelse ($reservations as $reservation)
                    @php
                        $variant = match ($reservation->status->value) {
                            'confirmed' => 'success',
                            'pending' => 'warning',
                            'cancelled', 'expired' => 'danger',
                            default => 'info',
                        };
                    @endphp
                    <div class="flex items-center justify-between py-2 border-b border-slate-50 text-sm">
                        <div>
                            @can('reservations.view')
                                <a href="{{ route('admin.reservations.show', $reservation) }}" class="text-slate-800 underline">{{ $reservation->activity?->title }}</a>
                            @else
                                <span class="text-slate-800">{{ $reservation->activity?->title }}</span>
                            @endcan
                            <p class="text-xs text-slate-400">{{ $reservation->reservation_date->toDateString() }} · {{ number_format((float) $reservation->total, 2) }} {{ $reservation->currency }}</p>
                        </div>
                        <x-admin.status-badge :variant="$variant" :label="__('admin.reservations.statuses.' . $reservation->status->value)" />
                    </div>
                @empty
                    <p class="text-sm text-slate-400">{{ __('admin.customers.no_bookings') }}</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.customers.account_title') }}</h2>

                @if ($deleted)
                    <x-admin.status-badge variant="neutral" :label="__('admin.accounts.deleted')" />
                @else
                    <x-admin.status-badge :variant="$customer->user->status === 'active' ? 'success' : 'danger'" :label="__('admin.statuses.' . $customer->user->status)" />
                @endif

                <p class="text-xs text-slate-400 mt-3">{{ __('admin.customers.joined') }}: {{ $customer->created_at->toFormattedDateString() }}</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($deleted)
                        @can('restore', $customer)
                            <x-admin.confirm-form :action="route('admin.customers.restore', $customer)" variant="success">{{ __('admin.customers.restore') }}</x-admin.confirm-form>
                        @endcan
                    @else
                        @can('update', $customer)
                            <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.customers.edit', $customer) }}">{{ __('admin.customers.edit') }}</x-admin.button>
                            @if ($customer->user->status === 'active')
                                <x-admin.confirm-form :action="route('admin.customers.suspend', $customer)" :confirm="__('admin.customers.confirm_suspend')" variant="danger">{{ __('admin.customers.suspend') }}</x-admin.confirm-form>
                            @else
                                <x-admin.confirm-form :action="route('admin.customers.reactivate', $customer)" variant="success">{{ __('admin.customers.reactivate') }}</x-admin.confirm-form>
                            @endif
                        @endcan
                        @can('delete', $customer)
                            <x-admin.confirm-form :action="route('admin.customers.destroy', $customer)" method="DELETE" :confirm="__('admin.customers.confirm_delete')" variant="danger" icon="trash">{{ __('admin.customers.delete') }}</x-admin.confirm-form>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
