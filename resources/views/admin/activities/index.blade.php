@extends('admin.layout')

@section('title', __('admin.activities.title'))
@section('page_title', __('admin.activities.title'))

@section('content')
    @if (session('status'))
        <x-admin.alert variant="success">
            {{ session('status') }}
        </x-admin.alert>
    @endif

    <div class="flex justify-between items-center mb-4">
        <div></div>
        @can('adminCreate', \App\Models\Activity::class)
            <x-admin.button href="{{ route('admin.activities.create') }}">{{ __('admin.activities.add') }}</x-admin.button>
        @endcan
    </div>

    <form method="GET" class="flex gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
               placeholder="{{ __('admin.activities.search_placeholder') }}"
               class="border border-slate-200 rounded-lg px-3 py-1.5">
        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.activities.all_statuses') }}</option>
            @foreach (['draft', 'approved', 'published', 'inactive', 'suspended'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                    {{ __('admin.activities.statuses.' . $status) }}
                </option>
            @endforeach
        </select>
        <select name="trashed" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.activities.current_activities') }}</option>
            <option value="only" @selected(($filters['trashed'] ?? '') === 'only')>{{ __('admin.activities.deleted_activities') }}</option>
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
                        <td class="py-2.5 px-4">
                            {{ $activity->title }}
                            @if ($activity->trashed())
                                <x-admin.status-badge variant="neutral" :label="__('admin.accounts.deleted')" />
                            @endif
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">
                            {{ $activity->provider->business_name }}
                            @unless ($activity->provider->isVerified())
                                <x-admin.status-badge variant="warning" :label="__('admin.providers.statuses.' . $activity->provider->verification_status)" />
                            @endunless
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $activity->category->name }}</td>
                        <td class="py-2.5 px-4">
                            @php
                                $shown = $activity->isSuspended() ? 'suspended' : $activity->status;
                                $variant = match ($shown) {
                                    'published' => 'success',
                                    'suspended' => 'danger',
                                    'inactive' => 'neutral',
                                    default => 'info',
                                };
                            @endphp
                            <x-admin.status-badge :variant="$variant" :label="__('admin.activities.statuses.' . $shown)" />
                            @if ($activity->isSuspended())
                                <p class="text-xs text-slate-400 mt-1">{{ $activity->suspension_reason }}</p>
                            @endif
                        </td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2">
                                @can('viewAsAdmin', \App\Models\Activity::class)
                                    <x-admin.button pill href="{{ route('admin.activities.show', $activity) }}">{{ __('admin.activities.view') }}</x-admin.button>
                                @endcan
                                @if ($activity->trashed())
                                    @can('adminRestore', $activity)
                                        <x-admin.confirm-form :action="route('admin.activities.restore', $activity)" variant="success">{{ __('admin.activities.restore') }}</x-admin.confirm-form>
                                    @endcan
                                @else
                                    @can('adminUpdate', $activity)
                                        <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.activities.edit', $activity) }}">{{ __('admin.activities.edit') }}</x-admin.button>
                                    @endcan
                                    @can('adminDelete', $activity)
                                        <x-admin.confirm-form :action="route('admin.activities.destroy', $activity)" method="DELETE" :confirm="__('admin.activities.confirm_delete')" variant="danger" icon="trash">{{ __('admin.activities.delete') }}</x-admin.confirm-form>
                                    @endcan
                                @endif
                                @unless ($activity->trashed())
                                @can('suspend', $activity)
                                    @can('feature', $activity)
                                        <form method="POST" action="{{ route('admin.activities.feature', $activity) }}">
                                            @csrf
                                            <x-admin.button pill variant="{{ $activity->is_featured ? 'neutral' : 'info' }}" type="submit">{{ $activity->is_featured ? __('admin.activities.unfeature') : __('admin.activities.feature') }}</x-admin.button>
                                        </form>
                                    @endcan
                                    @if ($activity->isSuspended())
                                        <form method="POST" action="{{ route('admin.activities.lift', $activity) }}">
                                            @csrf
                                            <x-admin.button pill variant="success" type="submit">{{ __('admin.activities.lift') }}</x-admin.button>
                                        </form>
                                    @elseif (in_array($activity->status, ['published', 'approved', 'inactive'], true))
                                        <form method="POST" action="{{ route('admin.activities.suspend', $activity) }}"
                                              onsubmit="return confirm('{{ __('admin.activities.confirm_suspend') }}') && (this.suspension_reason.value = prompt('{{ __('admin.activities.reason_prompt') }}') || '') !== ''">
                                            @csrf
                                            <input type="hidden" name="suspension_reason" value="">
                                            <x-admin.button pill variant="danger" type="submit">{{ __('admin.activities.suspend') }}</x-admin.button>
                                        </form>
                                    @endif
                                @endcan
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $activities->links() }}</div>
@endsection
