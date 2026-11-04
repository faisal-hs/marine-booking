@extends('admin.layout')

@section('title', __('admin.providers.title'))
@section('page_title', __('admin.providers.title'))

@section('content')
    <div class="flex justify-between items-center mb-4">
        <div class="flex gap-2 text-xs">
            @foreach (['pending', 'verified', 'rejected'] as $status)
                <a href="{{ route('admin.providers.index', array_merge($filters, ['status' => $status])) }}"
                    class="px-3 py-1 rounded-full border {{ ($filters['status'] ?? '') === $status ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">
                    {{ __('admin.providers.statuses.' . $status) }}
                </a>
            @endforeach
        </div>
        @can('create', \App\Models\Provider::class)
            <x-admin.button href="{{ route('admin.providers.create') }}">{{ __('admin.providers.add') }}</x-admin.button>
        @endcan
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
            placeholder="{{ __('admin.providers.search_placeholder') }}"
            class="border border-slate-200 rounded-lg px-3 py-1.5 w-72">
        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.providers.all_statuses') }}</option>
            @foreach (['pending', 'verified', 'rejected'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.providers.statuses.' . $status) }}</option>
            @endforeach
        </select>
        <select name="account" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.providers.current_accounts') }}</option>
            <option value="active" @selected(($filters['account'] ?? '') === 'active')>{{ __('admin.statuses.active') }}</option>
            <option value="suspended" @selected(($filters['account'] ?? '') === 'suspended')>{{ __('admin.statuses.suspended') }}</option>
            <option value="deleted" @selected(($filters['account'] ?? '') === 'deleted')>{{ __('admin.providers.deleted_accounts') }}</option>
        </select>
        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.providers.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.business_name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.owner') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.verification') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.account') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.activities') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.providers.documents') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($providers as $provider)
                    @php
                        $verificationVariant = match ($provider->verification_status) {
                            'verified' => 'success',
                            'pending' => 'warning',
                            'rejected' => 'danger',
                            default => 'info',
                        };
                        $deleted = $provider->user->trashed();
                    @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $provider->business_name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">
                            <x-admin.account-name :user="$provider->user" />
                            <p class="text-xs text-slate-400">{{ $provider->user->phone }}</p>
                        </td>
                        <td class="py-2.5 px-4">
                            <x-admin.status-badge :variant="$verificationVariant" :label="__('admin.providers.statuses.' . $provider->verification_status)" />
                        </td>
                        <td class="py-2.5 px-4">
                            @if ($deleted)
                                <x-admin.status-badge variant="neutral" :label="__('admin.accounts.deleted')" />
                            @else
                                <x-admin.status-badge :variant="$provider->user->status === 'active' ? 'success' : 'danger'" :label="__('admin.statuses.' . $provider->user->status)" />
                            @endif
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $provider->activities_count }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $provider->documents_count }}</td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2 justify-end">
                                @can('view', $provider)
                                    <x-admin.button pill href="{{ route('admin.providers.show', $provider) }}">{{ __('admin.reservations.view') }}</x-admin.button>
                                @endcan
                                @if ($deleted)
                                    @can('restore', $provider)
                                        <x-admin.confirm-form :action="route('admin.providers.restore', $provider)" variant="success">{{ __('admin.providers.restore') }}</x-admin.confirm-form>
                                    @endcan
                                @else
                                    @can('update', $provider)
                                        <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.providers.edit', $provider) }}">{{ __('admin.providers.edit') }}</x-admin.button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 px-4 text-center text-slate-400">{{ __('admin.providers.no_providers') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $providers->links() }}</div>
@endsection
