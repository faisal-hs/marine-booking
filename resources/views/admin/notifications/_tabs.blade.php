{{-- The three parts of the Notifications area. All need notifications.view. --}}
@php
    $tabs = [
        ['route' => 'admin.notifications.index', 'match' => 'admin.notifications.index', 'label' => 'admin.notifications.inbox'],
        ['route' => 'admin.notifications.log', 'match' => 'admin.notifications.log*', 'label' => 'admin.notifications.all_notifications'],
        ['route' => 'admin.notifications.broadcasts.index', 'match' => 'admin.notifications.broadcasts*', 'label' => 'admin.notifications.announcements'],
    ];
@endphp

<div class="flex gap-2 mb-6 text-sm border-b border-slate-200">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
            class="px-4 py-2 -mb-px border-b-2 {{ request()->routeIs($tab['match']) ? 'border-slate-800 text-slate-800 font-medium' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
            {{ __($tab['label']) }}
        </a>
    @endforeach
</div>
