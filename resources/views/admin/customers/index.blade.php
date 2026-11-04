@extends('admin.layout')

@section('title', __('admin.customers.title'))
@section('page_title', __('admin.customers.title'))

@section('content')
    <div class="flex justify-between items-center mb-4">
        <div></div>
        @can('create', \App\Models\Customer::class)
            <x-admin.button href="{{ route('admin.customers.create') }}">{{ __('admin.customers.add') }}</x-admin.button>
        @endcan
    </div>

    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
            placeholder="{{ __('admin.customers.search_placeholder') }}"
            class="border border-slate-200 rounded-lg px-3 py-1.5 w-72">
        <select name="account" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.customers.current_accounts') }}</option>
            <option value="active" @selected(($filters['account'] ?? '') === 'active')>{{ __('admin.statuses.active') }}</option>
            <option value="suspended" @selected(($filters['account'] ?? '') === 'suspended')>{{ __('admin.statuses.suspended') }}</option>
            <option value="deleted" @selected(($filters['account'] ?? '') === 'deleted')>{{ __('admin.customers.deleted_accounts') }}</option>
        </select>
        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.customers.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.dashboard.name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.customers.language') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.customers.account') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.customers.bookings') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.customers.joined') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    @php
                        $deleted = $customer->user->trashed();
                    @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">
                            <x-admin.account-name :user="$customer->user" />
                            <p class="text-xs text-slate-400">{{ $customer->user->phone }}@if ($customer->user->email) · {{ $customer->user->email }}@endif</p>
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ strtoupper($customer->preferred_language) }}</td>
                        <td class="py-2.5 px-4">
                            @if ($deleted)
                                <x-admin.status-badge variant="neutral" :label="__('admin.accounts.deleted')" />
                            @else
                                <x-admin.status-badge :variant="$customer->user->status === 'active' ? 'success' : 'danger'" :label="__('admin.statuses.' . $customer->user->status)" />
                            @endif
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $customer->reservations_count }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $customer->created_at->toFormattedDateString() }}</td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2 justify-end">
                                @can('view', $customer)
                                    <x-admin.button pill href="{{ route('admin.customers.show', $customer) }}">{{ __('admin.reservations.view') }}</x-admin.button>
                                @endcan
                                @if ($deleted)
                                    @can('restore', $customer)
                                        <x-admin.confirm-form :action="route('admin.customers.restore', $customer)" variant="success">{{ __('admin.customers.restore') }}</x-admin.confirm-form>
                                    @endcan
                                @else
                                    @can('update', $customer)
                                        <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.customers.edit', $customer) }}">{{ __('admin.customers.edit') }}</x-admin.button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 px-4 text-center text-slate-400">{{ __('admin.customers.no_customers') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>
@endsection
