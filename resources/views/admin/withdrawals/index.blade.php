@extends('admin.layout')

@section('title', __('admin.withdrawals.title'))
@section('page_title', __('admin.withdrawals.title'))

@section('content')
    <div class="flex flex-wrap gap-2 mb-4 text-xs">
        @foreach (['pending', 'processing', 'paid', 'rejected', 'failed', 'cancelled', 'all'] as $tab)
            <a href="{{ route('admin.withdrawals.index', array_filter(['status' => $tab, 'search' => $search])) }}"
                class="px-3 py-1 rounded-full border {{ $status === $tab ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">
                {{ __('admin.withdrawals.statuses.' . $tab) }} ({{ $counts[$tab] ?? 0 }})
            </a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="hidden" name="status" value="{{ $status }}">
        <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('admin.withdrawals.search_placeholder') }}"
            class="border border-slate-200 rounded-lg px-3 py-1.5 w-80">
        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.withdrawals.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.withdrawals.provider') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.withdrawals.amount') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.withdrawals.net') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.withdrawals.requested') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($withdrawals as $item)
                    @php
                        $variant = match ($item->status) {
                            'paid' => 'success',
                            'rejected', 'failed' => 'danger',
                            'processing' => 'info',
                            default => 'warning',
                        };
                    @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $item->provider->business_name }}</td>
                        <td class="py-2.5 px-4">{{ number_format($item->amount, 2) }} {{ $item->currency }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ number_format($item->net_amount, 2) }} {{ $item->currency }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $item->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$variant" :label="__('admin.withdrawals.statuses.' . $item->status)" /></td>
                        <td class="py-2.5 px-4">
                            <div class="flex justify-end">
                                <x-admin.button pill href="{{ route('admin.withdrawals.show', $item) }}">{{ __('admin.reservations.view') }}</x-admin.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-slate-400">{{ __('admin.withdrawals.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $withdrawals->links() }}</div>
@endsection
