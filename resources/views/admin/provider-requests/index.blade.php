@extends('admin.layout')

@section('title', __('admin.provider_requests.title'))
@section('page_title', __('admin.provider_requests.title'))

@section('content')
    <div class="flex flex-wrap gap-2 mb-4 text-xs">
        @foreach (['pending', 'approved', 'rejected', 'all'] as $tab)
            <a href="{{ route('admin.provider-requests.index', array_filter(['status' => $tab, 'source' => $filters['source'] ?? null, 'search' => $filters['search'] ?? null])) }}"
                class="px-3 py-1 rounded-full border {{ ($filters['status'] ?? 'pending') === $tab ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">
                {{ __('admin.provider_requests.tabs.' . $tab) }} ({{ $counts[$tab] }})
            </a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="hidden" name="status" value="{{ $filters['status'] ?? 'pending' }}">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
            placeholder="{{ __('admin.provider_requests.search_placeholder') }}"
            class="border border-slate-200 rounded-lg px-3 py-1.5 w-80">
        <select name="source" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.provider_requests.all_sources') }}</option>
            @foreach (['registration', 'customer_upgrade'] as $source)
                <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>
                    {{ __('admin.provider_requests.sources.' . $source) }}</option>
            @endforeach
        </select>
        <button type="submit"
            class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.provider_requests.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.provider_requests.applicant') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.provider_requests.business') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.provider_requests.source') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.provider_requests.submitted') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $item)
                    @php
                        $variant = match ($item->status) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default => 'warning',
                        };
                    @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">
                            <x-admin.account-name :user="$item->user" />
                            <p class="text-xs text-slate-400">{{ $item->user->phone }}</p>
                        </td>
                        <td class="py-2.5 px-4">
                            {{ $item->provider->business_name }}
                            <p class="text-xs text-slate-400">
                                {{ collect([$item->provider->city, $item->provider->area])->filter()->implode(' · ') }}</p>
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ __('admin.provider_requests.sources.' . $item->source) }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $item->submitted_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$variant"
                                :label="__('admin.provider_requests.statuses.' . $item->status)" /></td>
                        <td class="py-2.5 px-4">
                            <div class="flex justify-end">
                                <x-admin.button pill
                                    href="{{ route('admin.provider-requests.show', $item) }}">{{ __('admin.reservations.view') }}</x-admin.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 px-4 text-center text-slate-400">{{ __('admin.provider_requests.none') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
