@extends('admin.layout')

@section('title', __('admin.notifications.details'))
@section('page_title', __('admin.notifications.details'))

@section('content')
    @include('admin.notifications._tabs')

    @php
        $recipient = $notification->notifiable;
    @endphp

    <div class="max-w-3xl space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <dl class="text-sm space-y-3">
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.recipient') }}</dt>
                    <dd>
                        @if ($recipient)
                            <x-admin.account-name :user="$recipient" /> · {{ __('admin.notifications.recipient_types.' . $recipient->user_type) }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.class') }}</dt>
                    <dd class="font-mono text-xs break-all">{{ $notification->type }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.message') }}</dt>
                    <dd>{{ $notification->data['message'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.sent') }}</dt>
                    <dd>{{ $notification->created_at->format('Y-m-d H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">{{ __('admin.notifications.read_at') }}</dt>
                    <dd>{{ $notification->read_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-3">{{ __('admin.notifications.raw_data') }}</h2>
            {{-- Escaped: this is data a customer or provider's own input can end up in. --}}
            <pre class="text-xs bg-slate-50 rounded-lg p-4 overflow-x-auto">{{ json_encode($notification->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        <div class="flex items-center gap-3">
            <x-admin.button pill href="{{ route('admin.notifications.log') }}">{{ __('admin.notifications.back_to_log') }}</x-admin.button>
            @can('delete', $notification)
                <x-admin.confirm-form :action="route('admin.notifications.log.destroy', $notification->id)" method="DELETE" :confirm="__('admin.notifications.confirm_delete_for_user')" variant="danger" icon="trash">{{ __('admin.notifications.delete') }}</x-admin.confirm-form>
            @endcan
        </div>
    </div>
@endsection
