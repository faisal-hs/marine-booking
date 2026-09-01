{{--
Centralized icon system. Every icon path used anywhere in the admin
dashboard lives in exactly one place — this file — instead of being
duplicated inline as raw <svg> markup across individual Blade views.

    Usage:
    <x-admin.icon name="users" />
    <x-admin.icon name="edit" class="w-3 h-3" />
    <x-admin.icon name="trash" size="5" />

    Adding a new icon means adding one line to the $paths map below —
    never paste a new inline <svg> into a page again.
        --}}
        @props([
            'name',
            'size' => '4',
            'class' => '',
        ])

        @php
            $paths = [
                'home' => 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10',
                'users' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87M13 7a4 4 0 11-8 0 4 4 0 018 0zm6 3a4 4 0 11-8 0 4 4 0 018 0z',
                'building' => 'M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1',
                'user' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21v-1a6 6 0 0112 0v1',
                'tag' => 'M7 7h.01M4 4h6l10 10-6 6L4 10V4z',
                'map' => 'M9 20l-6-3V4l6 3m0 13l6-3m-6 3V7m6 10l6 3V6l-6-3m0 17V4',
                'calendar' => 'M8 7V3m8 4V3M3 11h18M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z',
                'card' => 'M2 7h20M2 11h20M4 5h16a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V7a2 2 0 012-2z',
                'bell' => 'M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2h16l-2-2z',
                'chart' => 'M3 3v18h18M8 17V9m5 8V5m5 12v-4',
                'lock' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm2-10V7a4 4 0 118 0v4',
                'cog' => 'M12 15a3 3 0 100-6 3 3 0 000 6z M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z',
                'edit' => 'M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7m-1.5-9.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 8.5-8.5z',
                'trash' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16',
                'check-circle' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'minus-circle' => 'M9 12h6m6 0a9 9 0 11-18 0 9 9 0 0118 0z',
                'x-circle' => 'M18.36 6.64a9 9 0 11-12.73 0M12 3v9',
            ];

            $path = $paths[$name] ?? null;
        @endphp

        @if ($path)
            <svg {{ $attributes->merge(['class' => "w-{$size} h-{$size} shrink-0 {$class}"]) }} fill="none"
                stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}" />
            </svg>
        @endif
