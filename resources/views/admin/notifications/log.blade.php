@extends('admin.layout')

@section('title', __('admin.notifications.log_title'))
@section('page_title', __('admin.notifications.title'))

@section('content')
    @include('admin.notifications._tabs')

    <p class="text-xs text-slate-400 mb-4">{{ __('admin.notifications.log_note') }}</p>

    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('admin.notifications.search_placeholder') }}"
            class="border border-slate-200 rounded-lg px-3 py-1.5 w-72">
        <select name="recipient" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.notifications.all_recipients') }}</option>
            @foreach (['customer', 'provider', 'admin'] as $kind)
                <option value="{{ $kind }}" @selected(($filters['recipient'] ?? '') === $kind)>{{ __('admin.notifications.recipient_types.' . $kind) }}</option>
            @endforeach
        </select>
        <select name="type" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.notifications.all_types') }}</option>
            @foreach ($types as $type)
                <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ \Illuminate\Support\Str::headline(preg_replace('/Notification$/', '', class_basename($type))) }}</option>
            @endforeach
        </select>
        <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.notifications.all_states') }}</option>
            <option value="unread" @selected(($filters['status'] ?? '') === 'unread')>{{ __('admin.notifications.status_unread') }}</option>
            <option value="read" @selected(($filters['status'] ?? '') === 'read')>{{ __('admin.notifications.status_read') }}</option>
        </select>
        <label class="flex items-center gap-1 text-slate-500">{{ __('admin.notifications.from') }}
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="border border-slate-200 rounded-lg px-2 py-1.5">
        </label>
        <label class="flex items-center gap-1 text-slate-500">{{ __('admin.notifications.to') }}
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="border border-slate-200 rounded-lg px-2 py-1.5">
        </label>
        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.notifications.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50 text-left">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.sent') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.recipient') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.type') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.message') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.notifications.state') }}</th>
                    <th class="py-2.5 px-4 font-medium text-right">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($notifications as $notification)
                    @php
                        $recipient = $notification->notifiable;
                    @endphp
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4 text-slate-500 whitespace-nowrap">{{ $notification->created_at->format('Y-m-d H:i') }}</td>
                        <td class="py-2.5 px-4">
                            @if ($recipient)
                                <x-admin.account-name :user="$recipient" />
                                <p class="text-xs text-slate-400">{{ __('admin.notifications.recipient_types.' . $recipient->user_type) }}</p>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-4 text-slate-500">{{ \Illuminate\Support\Str::headline(preg_replace('/Notification$/', '', class_basename($notification->type))) }}</td>
                        <td class="py-2.5 px-4 text-slate-600">{{ \Illuminate\Support\Str::limit($notification->data['message'] ?? '', 80) }}</td>
                        <td class="py-2.5 px-4">
                            <x-admin.status-badge :variant="$notification->read_at ? 'neutral' : 'info'" :label="$notification->read_at ? __('admin.notifications.status_read') : __('admin.notifications.status_unread')" />
                        </td>
                        <td class="py-2.5 px-4">
                            <div class="flex items-center gap-2 justify-end">
                                <x-admin.button pill href="{{ route('admin.notifications.log.show', $notification->id) }}">{{ __('admin.reservations.view') }}</x-admin.button>
                                @can('delete', $notification)
                                    <x-admin.confirm-form :action="route('admin.notifications.log.destroy', $notification->id)" method="DELETE" :confirm="__('admin.notifications.confirm_delete_for_user')" variant="danger" icon="trash">{{ __('admin.notifications.delete') }}</x-admin.confirm-form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-6 px-4 text-center text-slate-400">{{ __('admin.notifications.no_results') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
@endsection
