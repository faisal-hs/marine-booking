<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('admin.brand'))</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        th, td { text-align: start !important; }
        body {
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
        }

        .sidebar-bg {
            background: linear-gradient(180deg, #0B2E33 0%, #0E3B42 100%);
        }

        .nav-active {
            background: rgba(20, 184, 166, 0.15);
            color: #5EEAD4;
            border-right: 3px solid #14B8A6;
        }

        html[dir="rtl"] .nav-active {
            border-right: none;
            border-left: 3px solid #14B8A6;
        }

        .nav-item {
            color: #94A3B8;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #E2E8F0;
        }
    </style>
</head>

<body class="bg-[#F7FAFA] text-[#0F172A] antialiased">
    <div class="flex min-h-screen">
        <aside class="sidebar-bg w-64 shrink-0 flex flex-col">
            <div class="px-6 py-5 border-b border-white/10">
                <p class="text-white font-semibold tracking-tight">{{ __('admin.brand') }}</p>
                <p class="text-[11px] text-teal-300/70 mt-0.5">{{ __('admin.brand_subtitle') }}</p>
            </div>

            <nav class="flex-1 py-4 space-y-1 overflow-y-auto">
                @php
                    $navItems = [
                        ['key' => 'dashboard', 'route' => 'admin.dashboard', 'permission' => null, 'icon' => 'home'],
                        ['key' => 'users', 'route' => 'admin.users.index', 'permission' => 'users.view', 'icon' => 'users'],
                        ['key' => 'providers', 'route' => null, 'permission' => 'providers.view', 'icon' => 'building'],
                        ['key' => 'customers', 'route' => null, 'permission' => 'customers.view', 'icon' => 'user'],
                        ['key' => 'categories', 'route' => 'admin.categories.index', 'permission' => 'categories.view', 'icon' => 'tag'],    ['key' => 'activities', 'route' => null, 'permission' => 'activities.view', 'icon' => 'map'],
                        ['key' => 'reservations', 'route' => null, 'permission' => 'reservations.view', 'icon' => 'calendar'],
                        ['key' => 'payments', 'route' => null, 'permission' => 'payments.view', 'icon' => 'card'],
                        ['key' => 'notifications', 'route' => null, 'permission' => 'notifications.view', 'icon' => 'bell'],
                        ['key' => 'reports', 'route' => null, 'permission' => 'reports.view', 'icon' => 'chart'],
                        ['key' => 'roles', 'route' => null, 'permission' => 'roles.view', 'icon' => 'lock'],
                        ['key' => 'settings', 'route' => null, 'permission' => 'settings.view', 'icon' => 'cog'],
                    ];

                    $icons = [
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
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @if (is_null($item['permission']) || auth()->user()->can($item['permission']))
                        @if ($item['route'] && \Illuminate\Support\Facades\Route::has($item['route']))
                            <a href="{{ route($item['route']) }}"
                                class="nav-item flex items-center gap-3 px-6 py-2.5 text-sm {{ request()->routeIs($item['route'] . '*') ? 'nav-active' : '' }}">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
                                </svg>
                                {{ __('admin.nav.' . $item['key']) }}
                            </a>
                        @else
                            <div class="flex items-center justify-between px-6 py-2.5 text-sm text-slate-500">
                                <span class="flex items-center gap-3">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
                                    </svg>
                                    {{ __('admin.nav.' . $item['key']) }}
                                </span>
                                <span
                                    class="text-[10px] bg-white/5 text-slate-500 rounded px-1.5 py-0.5">{{ __('admin.nav.soon') }}</span>
                            </div>
                        @endif
                    @endif
                @endforeach
            </nav>

            <div class="px-6 py-4 border-t border-white/10">
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="text-sm text-slate-400 hover:text-white transition">{{ __('admin.logout') }}</button>
                </form>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0">
            <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between">
                <h1 class="text-base font-semibold text-slate-800">@yield('page_title', __('admin.dashboard.title'))
                </h1>
                <div class="flex items-center gap-4 text-sm">
                    <div class="flex items-center gap-1 text-xs">
                        <a href="{{ route('admin.locale.switch', 'en') }}"
                            class="{{ app()->getLocale() === 'en' ? 'font-semibold text-teal-700' : 'text-slate-400' }}">EN</a>
                        <span class="text-slate-300">/</span>
                        <a href="{{ route('admin.locale.switch', 'ar') }}"
                            class="{{ app()->getLocale() === 'ar' ? 'font-semibold text-teal-700' : 'text-slate-400' }}">AR</a>
                    </div>
                    <span class="text-slate-600">{{ auth()->user()->name }}</span>
                    <span class="text-[11px] bg-teal-50 text-teal-700 rounded-full px-2.5 py-1 font-medium">
                        {{ auth()->user()->getRoleNames()->first() ?? 'no role' }}
                    </span>
                </div>
            </header>

            <main class="flex-1 p-8">
                @if (session('status'))
                    <div class="mb-6 rounded-lg bg-teal-50 border border-teal-200 text-teal-800 text-sm px-4 py-3">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>

</html>
