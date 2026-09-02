@extends('admin.layout')

@section('title', __('admin.reservations.details_title'))
@section('page_title', __('admin.reservations.details_title') . ' #' . $reservation->id)

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.booking_details_title') }}
                </h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.activity') }}</dt>
                        <dd>{{ $reservation->activity->title }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.booking_option') }}</dt>
                        <dd>{{ $reservation->bookingOption->name }}
                            ({{ $reservation->bookingOption->booking_model->value }})</dd>
                    </div>
                    @if ($reservation->schedulePeriod)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.schedule_period') }}</dt>
                            <dd>
                                {{ __('admin.activities.weekdays.' . \App\Models\ActivitySchedulePeriod::dayNameFromNumber($reservation->schedulePeriod->day_of_week)) }}
                                {{ \Illuminate\Support\Carbon::parse($reservation->schedulePeriod->start_time)->format('H:i') }}–{{ \Illuminate\Support\Carbon::parse($reservation->schedulePeriod->end_time)->format('H:i') }}
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.date') }}</dt>
                        <dd>{{ $reservation->reservation_date->toDateString() }}</dd>
                    </div>
                    @if ($reservation->participants)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.participants') }}</dt>
                            <dd>{{ collect($reservation->participants)->map(fn($qty, $type) => "{$type}: {$qty}")->implode(', ') }}
                            </dd>
                        </div>
                    @endif
                    @if ($reservation->quantity)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.quantity') }}</dt>
                            <dd>{{ $reservation->quantity }}</dd>
                        </div>
                    @endif
                    @if ($reservation->duration)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.duration') }}</dt>
                            <dd>{{ $reservation->duration }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.pricing_title') }}</h2>
                <dl class="text-sm space-y-2 mb-4">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.pricing_model') }}</dt>
                        <dd>{{ $reservation->pricing_model }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.subtotal') }}</dt>
                        <dd>{{ number_format((float) $reservation->subtotal, 2) }} {{ $reservation->currency }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.discount') }}</dt>
                        <dd>{{ number_format((float) $reservation->discount, 2) }} {{ $reservation->currency }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.tax') }}</dt>
                        <dd>{{ number_format((float) $reservation->tax, 2) }} {{ $reservation->currency }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 font-medium">{{ __('admin.reservations.total') }}</dt>
                        <dd class="font-medium">{{ number_format((float) $reservation->total, 2) }}
                            {{ $reservation->currency }}</dd>
                    </div>
                </dl>

                @if (!empty($reservation->pricing_breakdown))
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-slate-400 border-b border-slate-100">
                                @foreach (array_keys($reservation->pricing_breakdown[0] ?? []) as $key)
                                    <th class="py-1.5 text-left font-medium">{{ ucfirst(str_replace('_', ' ', $key)) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reservation->pricing_breakdown as $line)
                                <tr class="border-b border-slate-50">
                                    @foreach ($line as $value)
                                        <td class="py-1.5 text-slate-500">
                                            {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.status_title') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.status') }}</dt>
                        <dd>
                            @php
                                $variant = match ($reservation->status->value) {
                                    'confirmed' => 'success',
                                    'pending' => 'warning',
                                    'cancelled', 'expired' => 'danger',
                                    default => 'info',
                                };
                            @endphp
                            <x-admin.status-badge :variant="$variant" :label="__('admin.reservations.statuses.' . $reservation->status->value)" />
                        </dd>
                    </div>
                    @if ($reservation->status->value === 'pending' && $reservation->hold_expires_at)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.hold_expires_at') }}</dt>
                            <dd>{{ $reservation->hold_expires_at->toDateTimeString() }}</dd>
                        </div>
                    @endif
                    @if ($reservation->confirmed_at)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.confirmed_at') }}</dt>
                            <dd>{{ $reservation->confirmed_at->toDateTimeString() }}</dd>
                        </div>
                    @endif
                    @if ($reservation->cancelled_at)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reservations.cancelled_at') }}</dt>
                            <dd>{{ $reservation->cancelled_at->toDateTimeString() }}</dd>
                        </div>
                        @if ($reservation->cancellation_reason)
                            <div>
                                <dt class="text-slate-400">{{ __('admin.reservations.cancellation_reason') }}</dt>
                                <dd>{{ $reservation->cancellation_reason }}</dd>
                            </div>
                        @endif
                    @endif
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.created_at') }}</dt>
                        <dd>{{ $reservation->created_at->toDateTimeString() }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.updated_at') }}</dt>
                        <dd>{{ $reservation->updated_at->toDateTimeString() }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.customer') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.customer_name') }}</dt>
                        <dd>{{ $reservation->customer->user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.email') }}</dt>
                        <dd>{{ $reservation->customer->user->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.phone') }}</dt>
                        <dd>{{ $reservation->customer->user->phone ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
@endsection
