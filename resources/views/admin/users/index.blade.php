@extends('admin.layout')

@section('title', __('admin.users.title'))
@section('page_title', __('admin.users.title'))

@section('content')
    <div class="flex justify-between items-center mb-4">
        <div></div>
        @can('users.create')
            <a href="{{ route('admin.users.create') }}"
                class="text-xs bg-[#0B2E33] text-white rounded-lg px-4 py-2 hover:bg-[#0E3B42] transition">{{ __('admin.users.add') }}</a>
        @endcan
    </div>

    <form method="GET" class="flex gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
            placeholder="{{ __('admin.users.search_placeholder') }}" class="border border-slate-200 rounded-lg px-3 py-1.5">
        <select name="user_type" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.users.all_types') }}</option>
            @foreach (['admin', 'provider', 'customer'] as $type)
                <option value="{{ $type }}" @selected(($filters['user_type'] ?? '') === $type)>{{ ucfirst($type) }}</option>
            @endforeach
        </select>
        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.users.all_statuses') }}</option>
            @foreach (['active', 'inactive', 'suspended'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.statuses.' . $status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.users.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.dashboard.name') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.dashboard.type') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.users.contact') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.users.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.users.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $user->name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ ucfirst($user->user_type) }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $user->email ?? $user->phone ?? '—' }}</td>
                        <td class="py-2.5 px-4">
                            @php
                                $statusStyles = [
                                    'active' => ['bg' => 'bg-green-50 text-green-700 border-green-200', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                                    'inactive' => ['bg' => 'bg-slate-100 text-slate-600 border-slate-200', 'icon' => 'M9 12h6m6 0a9 9 0 11-18 0 9 9 0 0118 0z'],
                                    'suspended' => ['bg' => 'bg-red-50 text-red-700 border-red-200', 'icon' => 'M18.36 6.64a9 9 0 11-12.73 0M12 3v9'],
                                ];
                                $style = $statusStyles[$user->status];
                            @endphp
                            <span
                                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs border {{ $style['bg'] }}">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $style['icon'] }}" />
                                </svg>
                                {{ __('admin.statuses.' . $user->status) }}
                            </span>
                        </td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2 justify-end">
                                @can('users.view')
                                    <a href="{{ route('admin.users.show', $user) }}"
                                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-slate-100 text-slate-600 hover:bg-slate-200 transition">
                                        {{ __('admin.users.view') }}
                                    </a>
                                @endcan
                                @can('users.update')
                                    <a href="{{ route('admin.users.edit', $user) }}"
                                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7m-1.5-9.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 8.5-8.5z" />
                                        </svg>
                                        {{ __('admin.users.edit') }}
                                    </a>
                                @endcan
                                @can('users.delete')
                                    @if ($user->id !== auth()->id())
                                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                            onsubmit="return confirm('{{ __('admin.users.confirm_delete') }}')">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs bg-red-50 text-red-700 hover:bg-red-100 transition">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                                    stroke-width="2">
                                                    <circle cx="12" cy="12" r="9" stroke-linecap="round" />
                                                    <path stroke-linecap="round" d="M9 9l6 6m0-6l-6 6" />
                                                </svg>
                                                {{ __('admin.users.delete') }}
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
@endsection
