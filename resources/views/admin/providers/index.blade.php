@extends('admin.layout')

@section('title', __('admin.providers.title'))
@section('page_title', __('admin.providers.title'))

@section('content')
    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
            placeholder="{{ __('admin.providers.search_placeholder') }}"
            class="border border-slate-200 rounded-lg px-3 py-1.5">

        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.providers.all_statuses') }}</option>
            @foreach (['pending', 'verified', 'rejected'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.providers.statuses.' . $status) }}
                </option>
            @endforeach
        </select>

        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.reservations.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.business_name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.customer') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.documents') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($providers as $provider)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $provider->business_name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $provider->user->name }}</td>
                        <td class="py-2.5 px-4">
                            @php
                                $variant = match ($provider->verification_status) {
                                    'verified' => 'success',
                                    'pending' => 'warning',
                                    'rejected' => 'danger',
                                    default => 'info',
                                };
                            @endphp
                            <x-admin.status-badge :variant="$variant" :label="__('admin.providers.statuses.' . $provider->verification_status)" />
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $provider->documents_count }}</td>
                        <td class="py-2.5 px-4">
                            <a href="{{ route('admin.providers.show', $provider) }}"
                                class="text-sm text-[var(--brand-accent,theme(colors.slate.700))] underline">
                                {{ __('admin.reservations.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-6 px-4 text-center text-slate-400">{{ __('admin.providers.no_providers') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $providers->links() }}</div>
@endsection
