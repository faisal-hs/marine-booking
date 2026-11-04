@extends('admin.layout')

@section('title', $broadcast->title)
@section('page_title', __('admin.notifications.title'))

@section('content')
    @include('admin.notifications._tabs')

    @php
        $variant = match ($broadcast->status) {
            'sent' => 'success',
            'sending' => 'warning',
            'failed' => 'danger',
            default => 'neutral',
        };
    @endphp

    <div class="max-w-3xl space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-800">{{ $broadcast->title }}</h2>
                <x-admin.status-badge :variant="$variant" :label="__('admin.notifications.statuses.' . $broadcast->status)" />
            </div>

            {{-- Escaped, and line breaks kept. --}}
            <p class="whitespace-pre-line text-slate-700">{{ $broadcast->body }}</p>

            <dl class="grid grid-cols-2 gap-4 text-sm border-t border-slate-100 pt-4">
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.audience') }}</dt>
                    <dd>
                        {{ __('admin.notifications.audiences.' . $broadcast->audience) }}
                        @if ($broadcast->audience === 'user' && $broadcast->recipient)
                            ({{ $broadcast->recipient->name }})
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.created_by') }}</dt>
                    <dd>{{ $broadcast->creator?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.recipients') }}</dt>
                    <dd>
                        @if ($broadcast->isDraft())
                            {{ __('admin.notifications.would_reach', ['count' => $audienceSize]) }}
                        @else
                            {{ __('admin.notifications.reached', ['count' => $broadcast->delivered_count]) }} / {{ $audienceSize }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.sent_at') }}</dt>
                    <dd>{{ $broadcast->sent_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
            </dl>

            @if ($broadcast->status === 'failed' && $broadcast->failed_reason)
                <x-admin.notice variant="danger">{{ __('admin.notifications.failed_reason') }}: {{ $broadcast->failed_reason }}</x-admin.notice>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-admin.button pill href="{{ route('admin.notifications.broadcasts.index') }}">{{ __('admin.notifications.back_to_broadcasts') }}</x-admin.button>

            @if ($broadcast->isDraft())
                @can('update', $broadcast)
                    <x-admin.button pill variant="info" icon="edit" href="{{ route('admin.notifications.broadcasts.edit', $broadcast) }}">{{ __('admin.notifications.edit') }}</x-admin.button>
                @endcan
                @can('send', $broadcast)
                    <x-admin.confirm-form :action="route('admin.notifications.broadcasts.send', $broadcast)" :confirm="__('admin.notifications.confirm_send')" variant="success">{{ __('admin.notifications.send') }}</x-admin.confirm-form>
                @endcan
            @endif

            @if ($broadcast->status !== 'sending')
                @can('delete', $broadcast)
                    <x-admin.confirm-form :action="route('admin.notifications.broadcasts.destroy', $broadcast)" method="DELETE" :confirm="__('admin.notifications.confirm_delete_broadcast')" variant="danger" icon="trash">{{ __('admin.notifications.delete') }}</x-admin.confirm-form>
                @endcan
            @endif
        </div>
    </div>
@endsection
