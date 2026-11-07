@extends('admin.layout')

@section('title', __('admin.withdrawals.title'))
@section('page_title', __('admin.withdrawals.details_title'))

@section('content')
    @php
        $variant = match ($withdrawal->status) {
            'paid' => 'success',
            'rejected', 'failed' => 'danger',
            'processing' => 'info',
            default => 'warning',
        };
        $bank = $withdrawal->bank_snapshot ?? [];
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.withdrawals.details_title') }}</h2>
                    <x-admin.status-badge :variant="$variant" :label="__('admin.withdrawals.statuses.' . $withdrawal->status)" />
                </div>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-slate-400">{{ __('admin.withdrawals.provider') }}</dt>
                        <dd><a class="text-sky-700" href="{{ route('admin.providers.show', $withdrawal->provider) }}">{{ $withdrawal->provider->business_name }}</a></dd></div>
                    <div><dt class="text-slate-400">{{ __('admin.withdrawals.requested') }}</dt><dd>{{ $withdrawal->created_at->format('Y-m-d H:i') }}</dd></div>
                    <div><dt class="text-slate-400">{{ __('admin.withdrawals.amount') }}</dt><dd class="font-semibold">{{ number_format($withdrawal->amount, 2) }} {{ $withdrawal->currency }}</dd></div>
                    <div><dt class="text-slate-400">{{ __('admin.withdrawals.fee') }}</dt><dd>{{ number_format($withdrawal->fee, 2) }} {{ $withdrawal->currency }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400">{{ __('admin.withdrawals.net') }} ({{ __('admin.withdrawals.to_transfer') }})</dt>
                        <dd class="text-lg font-semibold">{{ number_format($withdrawal->net_amount, 2) }} {{ $withdrawal->currency }}</dd></div>
                    @if ($withdrawal->processed_at)
                        <div><dt class="text-slate-400">{{ __('admin.withdrawals.processed_by') }}</dt><dd>{{ $withdrawal->processor?->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-400">{{ __('admin.withdrawals.processed_at') }}</dt><dd>{{ $withdrawal->processed_at->format('Y-m-d H:i') }}</dd></div>
                    @endif
                    @if ($withdrawal->payout_reference)
                        <div class="col-span-2"><dt class="text-slate-400">{{ __('admin.withdrawals.payout_reference') }}</dt><dd>{{ $withdrawal->payout_reference }}</dd></div>
                    @endif
                    @if ($withdrawal->status_reason)
                        <div class="col-span-2"><dt class="text-slate-400">{{ __('admin.withdrawals.reason') }}</dt><dd>{{ $withdrawal->status_reason }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-1">{{ __('admin.withdrawals.bank_title') }}</h2>
                <p class="text-xs text-slate-500 mb-4">{{ __('admin.withdrawals.bank_note') }}</p>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-slate-400">{{ __('admin.withdrawals.account_holder') }}</dt><dd>{{ $bank['account_holder_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400">{{ __('admin.withdrawals.bank_name') }}</dt><dd>{{ $bank['bank_name'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400">IBAN</dt><dd class="font-mono tracking-wide" dir="ltr">{{ $bank['iban'] ?? '—' }}</dd></div>
                    @if (! empty($bank['swift_code']))
                        <div><dt class="text-slate-400">SWIFT</dt><dd class="font-mono" dir="ltr">{{ $bank['swift_code'] }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>

        <div class="space-y-6">
            @can('process', $withdrawal)
                @if (in_array($withdrawal->status, ['pending', 'processing'], true))
                    <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
                        <h2 class="text-sm font-semibold text-slate-800">{{ __('admin.withdrawals.actions_title') }}</h2>

                        @if ($withdrawal->status === 'pending')
                            <form method="POST" action="{{ route('admin.withdrawals.processing', $withdrawal) }}">
                                @csrf
                                <button type="submit" class="w-full bg-sky-600 text-white rounded-lg px-3 py-1.5 text-sm">{{ __('admin.withdrawals.start_processing') }}</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('admin.withdrawals.paid', $withdrawal) }}"
                            onsubmit="return confirm('{{ __('admin.withdrawals.confirm_paid') }}')">
                            @csrf
                            <input type="text" name="payout_reference" required placeholder="{{ __('admin.withdrawals.reference_placeholder') }}"
                                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm mb-2">
                            <button type="submit" class="w-full bg-emerald-600 text-white rounded-lg px-3 py-1.5 text-sm">{{ __('admin.withdrawals.mark_paid') }}</button>
                        </form>

                        <form method="POST" action="{{ route('admin.withdrawals.reject', $withdrawal) }}"
                            onsubmit="return confirm('{{ __('admin.withdrawals.confirm_reject') }}')">
                            @csrf
                            <input type="text" name="reason" required placeholder="{{ __('admin.withdrawals.reason_placeholder') }}"
                                class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm mb-2">
                            <button type="submit" class="w-full bg-red-600 text-white rounded-lg px-3 py-1.5 text-sm">{{ __('admin.withdrawals.reject') }}</button>
                        </form>

                        @if ($withdrawal->status === 'processing')
                            <form method="POST" action="{{ route('admin.withdrawals.fail', $withdrawal) }}"
                                onsubmit="return confirm('{{ __('admin.withdrawals.confirm_fail') }}')">
                                @csrf
                                <input type="text" name="reason" required placeholder="{{ __('admin.withdrawals.reason_placeholder') }}"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm mb-2">
                                <button type="submit" class="w-full bg-amber-600 text-white rounded-lg px-3 py-1.5 text-sm">{{ __('admin.withdrawals.mark_failed') }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
