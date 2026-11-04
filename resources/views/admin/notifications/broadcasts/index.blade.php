@extends('admin.layout')

@section('title', __('admin.notifications.broadcasts_title'))
@section('page_title', __('admin.notifications.title'))

@section('content')
    @include('admin.notifications._tabs')

    <div class="flex items-center justify-between mb-4">
        <div class="flex gap-2 text-xs">
            <a href="{{ route('admin.notifications.broadcasts.index') }}"
                class="px-3 py-1 rounded-full border {{ $status === null ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">{{ __('admin.notifications.all_statuses') }}</a>
            @foreach (['draft', 'sending', 'sent', 'failed'] as $state)
                <a href="{{ route('admin.notifications.broadcasts.index', ['status' => $state]) }}"
                    class="px-3 py-1 rounded-full border {{ $status === $state ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">{{ __('admin.notifications.statuses.' . $state) }}</a>
            @endforeach
        </div>

        @can('create', \App\Models\Broadcast::class)
            <x-admin.button href="{{ route('admin.notifications.broadcasts.create') }}">{{ __('admin.notifications.new_broadcast') }}</x-admin.button>
        @endcan
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.broadcast_title_field') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.audience') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.recipients') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.created_by') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($broadcasts as $broadcast)
                    @php
                        $variant = match ($broadcast->status) {
                            'sent' => 'success',
                            'sending' => 'warning',
                            'failed' => 'danger',
                            default => 'neutral',
                        };
                    @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4">{{ $broadcast->title }}</td>
                        <td class="py-2.5 px-4 text-slate-500">
                            {{ __('admin.notifications.audiences.' . $broadcast->audience) }}
                            @if ($broadcast->audience === 'user' && $broadcast->recipient)
                                <span class="text-xs text-slate-400">({{ $broadcast->recipient->name }})</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-4"><x-admin.status-badge :variant="$variant" :label="__('admin.notifications.statuses.' . $broadcast->status)" /></td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $broadcast->status === 'draft' ? '—' : $broadcast->delivered_count . ' / ' . $broadcast->recipients_count }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ $broadcast->creator?->name ?? '—' }}</td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2 justify-end">
                                <x-admin.button pill href="{{ route('admin.notifications.broadcasts.show', $broadcast) }}">{{ __('admin.notifications.view') }}</x-admin.button>
                                @if ($broadcast->isDraft())
                                    @can('update', $broadcast)
                                        <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.notifications.broadcasts.edit', $broadcast) }}">{{ __('admin.notifications.edit') }}</x-admin.button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 px-4 text-center text-slate-400">{{ __('admin.notifications.no_broadcasts') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $broadcasts->links() }}</div>
@endsection
