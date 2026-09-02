@extends('admin.layout')

@section('title', __('admin.payments.details_title'))
@section('page_title', __('admin.payments.details_title') . ' - ' . $payment->reference_id)

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.payments.details_title') }}</h2>
            <dl class="text-sm space-y-2">
                <div><dt class="text-slate-400">{{ __('admin.payments.reference') }}</dt><dd>{{ $payment->reference_id }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.reservations.activity') }}</dt><dd>{{ $payment->reservation->activity->title }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.payments.gateway') }}</dt><dd>{{ $payment->gateway }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.payments.payment_id') }}</dt><dd>{{ $payment->payment_id ?? '—' }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.payments.provider_reference') }}</dt><dd>{{ $payment->provider_reference ?? '—' }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.reservations.total') }}</dt><dd class="font-medium">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</dd></div>
                @if ((float) $payment->refunded_amount > 0)
                    <div><dt class="text-slate-400">{{ __('admin.payments.refunded_amount') }}</dt><dd>{{ number_format((float) $payment->refunded_amount, 2) }} {{ $payment->currency }}</dd></div>
                @endif
                @if ($payment->failure_reason)
                    <div><dt class="text-slate-400">{{ __('admin.payments.failure_reason') }}</dt><dd>{{ $payment->failure_reason }}</dd></div>
                @endif
                <div><dt class="text-slate-400">{{ __('admin.payments.paid_at') }}</dt><dd>{{ $payment->paid_at?->toDateTimeString() ?? '—' }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.reservations.created_at') }}</dt><dd>{{ $payment->created_at->toDateTimeString() }}</dd></div>
            </dl>
        </div>

        @if (!empty($payment->raw_payload))
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.payments.raw_payload') }}</h2>
                <pre class="text-xs text-slate-500 whitespace-pre-wrap">{{ json_encode($payment->raw_payload, JSON_PRETTY_PRINT) }}</pre>
            </div>
        @endif
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.status') }}</h2>
            @php
                $variant = match ($payment->status->value) {
                    'succeeded' => 'success',
                    'pending' => 'warning',
                    'failed' => 'danger',
                    default => 'info',
                };
            @endphp
            <x-admin.status-badge :variant="$variant" :label="__('admin.payments.statuses.' . $payment->status->value)" />

            @can('refund', $payment)
                @if (in_array($payment->status->value, ['succeeded', 'partially_refunded']))
                    <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" class="mt-4 space-y-2"
                          onsubmit="return confirm('{{ __('admin.payments.confirm_refund') }}')">
                        @csrf
                        <label class="block text-xs text-slate-400">{{ __('admin.payments.refund_amount_hint') }}</label>
                        <input type="number" step="0.01" name="amount" placeholder="{{ __('admin.payments.full_amount') }}"
                               class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm">
                        <button type="submit" class="w-full bg-slate-800 text-white rounded-lg px-3 py-1.5 text-sm">
                            {{ __('admin.payments.refund') }}
                        </button>
                    </form>
                @endif
            @endcan
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.customer') }}</h2>
            <dl class="text-sm space-y-2">
                <div><dt class="text-slate-400">{{ __('admin.reservations.customer_name') }}</dt><dd>{{ $payment->reservation->customer->user->name }}</dd></div>
                <div><dt class="text-slate-400">{{ __('admin.users.email') }}</dt><dd>{{ $payment->reservation->customer->user->email ?? '—' }}</dd></div>
            </dl>
        </div>
    </div>
</div>
@endsection
