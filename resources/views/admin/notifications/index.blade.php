@extends('admin.layout')

@section('title', __('admin.notifications.title'))
@section('page_title', __('admin.notifications.title'))

@section('content')
    @include('admin.notifications._tabs')

    <div class="flex items-center justify-between mb-4 text-sm">
        <div class="flex gap-2">
            <a href="{{ route('admin.notifications.index') }}"
                class="px-3 py-1 rounded-full border {{ $status === 'all' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">{{ __('admin.notifications.all') }}</a>
            <a href="{{ route('admin.notifications.index', ['status' => 'unread']) }}"
                class="px-3 py-1 rounded-full border {{ $status === 'unread' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-200' }}">
                {{ __('admin.notifications.unread_count', ['count' => $unreadCount]) }}
            </a>
        </div>

        @if ($unreadCount > 0)
            <x-admin.confirm-form :action="route('admin.notifications.read-all')" variant="neutral">{{ __('admin.notifications.mark_all_read') }}</x-admin.confirm-form>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">
        @forelse ($notifications as $notification)
            @php
                $data = $notification->data;
                $unread = $notification->read_at === null;
                $link = $links->urlFor($data, auth()->user());
            @endphp
            <div class="p-4 flex items-start justify-between gap-4 text-sm {{ $unread ? 'bg-slate-50' : '' }}">
                <div class="flex items-start gap-3">
                    <span class="mt-1.5 inline-block w-2 h-2 rounded-full {{ $unread ? 'bg-sky-500' : 'bg-transparent' }}"></span>
                    <div>
                        @if (! empty($data['title']))
                            <p class="font-medium text-slate-800">{{ $data['title'] }}</p>
                        @endif
                        <p class="{{ $unread ? 'text-slate-800' : 'text-slate-500' }}">{{ $data['message'] ?? \Illuminate\Support\Str::headline(class_basename($notification->type)) }}</p>
                        <p class="text-xs text-slate-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    @if ($link)
                        <x-admin.button pill variant="info" href="{{ $link }}">{{ __('admin.notifications.open') }}</x-admin.button>
                    @endif
                    @if ($unread)
                        <x-admin.confirm-form :action="route('admin.notifications.read', $notification->id)">{{ __('admin.notifications.mark_read') }}</x-admin.confirm-form>
                    @else
                        <x-admin.confirm-form :action="route('admin.notifications.unread', $notification->id)">{{ __('admin.notifications.mark_unread') }}</x-admin.confirm-form>
                    @endif
                    <x-admin.confirm-form :action="route('admin.notifications.destroy', $notification->id)" method="DELETE" :confirm="__('admin.notifications.confirm_delete')" variant="danger" icon="trash">{{ __('admin.notifications.delete') }}</x-admin.confirm-form>
                </div>
            </div>
        @empty
            <p class="p-6 text-center text-slate-400 text-sm">{{ $status === 'unread' ? __('admin.notifications.empty_unread') : __('admin.notifications.empty') }}</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
@endsection
