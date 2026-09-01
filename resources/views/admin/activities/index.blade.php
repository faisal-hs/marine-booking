@extends('admin.layout')

@section('title', __('admin.activities.title'))
@section('page_title', __('admin.activities.title'))

@section('content')
    @if (session('status'))
        <x-admin.alert variant="success">
            {{ session('status') }}
        </x-admin.alert>
    @endif

    <form method="GET" class="flex gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
               placeholder="{{ __('admin.activities.search_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">
        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.activities.all_statuses') }}</option>
            @foreach (['draft', 'pending_review', 'published', 'rejected', 'inactive'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.activities.statuses.' . $status) }}
                </option>
            @endforeach
        </select>
        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.activities.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.activities.title_column') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.activities.provider') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.activities.category') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.activities.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.activities.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($activities as $activity)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $activity->title }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $activity->provider->business_name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $activity->category->name }}</td>
                        <td class="py-2.5 px-4">
                            @php
        $variant = match ($activity->status) {
            'published' => 'success',
            'pending_review' => 'warning',
            'rejected' => 'danger',
            'inactive' => 'neutral',
            default => 'info',
        };
                            @endphp
                            <x-admin.status-badge :variant="$variant" :label="__('admin.activities.statuses.' . $activity->status)" />
                            @if ($activity->status === 'rejected' && $activity->rejection_reason)
                                <p class="text-xs text-slate-400 mt-1">{{ $activity->rejection_reason }}</p>
                            @endif
                        </td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2">
                                @can('approve', $activity)
                                    @if ($activity->status === 'pending_review')
                                        <form method="POST" action="{{ route('admin.activities.approve', $activity) }}">
                                            @csrf
                                            <x-admin.button pill variant="success" type="submit">{{ __('admin.activities.approve') }}</x-admin.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('reject', $activity)
                                    @if ($activity->status === 'pending_review')
                                        <form method="POST" action="{{ route('admin.activities.reject', $activity) }}"
                                              onsubmit="return confirm('{{ __('admin.activities.confirm_reject') }}') && (this.rejection_reason.value = prompt('{{ __('admin.activities.reason_prompt') }}') || '') !== ''">
                                            @csrf
                                            <input type="hidden" name="rejection_reason" value="">
                                            <x-admin.button pill variant="danger" type="submit">{{ __('admin.activities.reject') }}</x-admin.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('publish', $activity)
                                    @if ($activity->status === 'inactive')
                                        <form method="POST" action="{{ route('admin.activities.publish', $activity) }}">
                                            @csrf
                                            <x-admin.button pill variant="success" type="submit">{{ __('admin.activities.publish') }}</x-admin.button>
                                        </form>
                                    @elseif ($activity->status === 'published')
                                        <form method="POST" action="{{ route('admin.activities.deactivate', $activity) }}">
                                            @csrf
                                            <x-admin.button pill variant="neutral" type="submit">{{ __('admin.activities.deactivate') }}</x-admin.button>
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

    <div class="mt-4">{{ $activities->links() }}</div>
@endsection
