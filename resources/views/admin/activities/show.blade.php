@extends('admin.layout')

@section('title', __('admin.users.details'))
@section('page_title', __('admin.users.details'))

@section('content')
        <div class="max-w-2xl">
            <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-3 text-sm">
                <h1 class="text-lg font-semibold mb-4">{{ $user->name }}</h1>
                <p><span class="text-slate-400">{{ __('admin.users.user_type') }}:</span> {{ ucfirst($user->user_type) }}</p>
                <p><span class="text-slate-400">{{ __('admin.users.email') }}:</span> {{ $user->email ?? '—' }}</p>
                <p><span class="text-slate-400">{{ __('admin.users.phone') }}:</span> {{ $user->phone ?? '—' }}</p>
    <p>
        <span class="text-slate-400">{{ __('admin.users.status') }}:</span>
        @php
            $statusStyles = [
                'active' => 'bg-green-50 text-green-700 border-green-200',
                'inactive' => 'bg-slate-100 text-slate-600 border-slate-200',
                'suspended' => 'bg-red-50 text-red-700 border-red-200',
            ];
        @endphp
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs border {{ $statusStyles[$user->status] }}">
            {{ __('admin.statuses.' . $user->status) }}
        </span>
    </p>
                @if ($user->isProvider() && $user->provider)
                    <p><span class="text-slate-400">{{ __('admin.users.business') }}:</span> {{ $user->provider->business_name }}
                    </p>
                    <p><span class="text-slate-400">{{ __('admin.users.verification') }}:</span>
                        {{ ucfirst($user->provider->verification_status) }}</p>
                @endif

                @if ($user->isCustomer() && $user->customer)
                    <p><span class="text-slate-400">{{ __('admin.users.language_field') }}:</span>
                        {{ $user->customer->preferred_language }}</p>
                @endif

                @if ($user->isAdmin())
                    <p><span class="text-slate-400">{{ __('admin.users.roles') }}:</span>
                        {{ $user->getRoleNames()->implode(', ') ?: '—' }}</p>
                @endif

                <div class="pt-4 flex gap-3">
                    @can('users.update')
                        <a href="{{ route('admin.users.edit', $user) }}"
                            class="text-xs bg-[#0B2E33] text-white rounded-lg px-3 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.users.edit') }}</a>
                    @endcan

                    @if ($user->id !== auth()->id())
                        @can('users.update')
                            @if ($user->status === 'active')
                                <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="status" value="suspended">
                                    <button
                                        class="text-xs bg-red-600 text-white rounded-lg px-3 py-2">{{ __('admin.users.suspend') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="status" value="active">
                                    <button
                                        class="text-xs bg-teal-600 text-white rounded-lg px-3 py-2">{{ __('admin.users.activate') }}</button>
                                </form>
                            @endif
                        @endcan
                    @endif
                </div>
            </div>
        </div>
@endsection
