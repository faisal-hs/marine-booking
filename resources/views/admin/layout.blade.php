<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('branding.name'))</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    @include('admin.partials.theme-vars')
</head>

<body class="bg-[var(--brand-page-bg)] text-[var(--brand-ink)] antialiased">
    <div class="flex min-h-screen">
        <aside class="sidebar-bg w-64 shrink-0 flex flex-col">
            <div class="px-6 py-5 border-b border-white/10">
                <x-admin.logo variant="light" />
            </div>

            <nav class="flex-1 py-4 space-y-1 overflow-y-auto">
                @php
                    $navItems = [
                        ['key' => 'dashboard', 'route' => 'admin.dashboard', 'permission' => null, 'icon' => 'home'],
                        ['key' => 'users', 'route' => 'admin.users.index', 'permission' => 'users.view', 'icon' => 'users'],
                        ['key' => 'providers', 'route' => null, 'permission' => 'providers.view', 'icon' => 'building'],
                        ['key' => 'customers', 'route' => null, 'permission' => 'customers.view', 'icon' => 'user'],
                        ['key' => 'categories', 'route' => 'admin.categories.index', 'permission' => 'categories.view', 'icon' => 'tag'],
                        ['key' => 'activities', 'route' => 'admin.activities.index', 'permission' => 'activities.view', 'icon' => 'map'],
                        ['key' => 'feature_definitions', 'route' => 'admin.feature-definitions.index', 'permission' => 'feature_definitions.view', 'icon' => 'tag'],
                        ['key' => 'reservations', 'route' => 'admin.reservations.index', 'permission' => 'reservations.view', 'icon' => 'calendar'],
                        ['key' => 'payments', 'route' => 'admin.payments.index', 'permission' => 'payments.view', 'icon' => 'card'],
                        ['key' => 'notifications', 'route' => null, 'permission' => 'notifications.view', 'icon' => 'bell'],
                        ['key' => 'reports', 'route' => 'admin.reports.index', 'permission' => 'reports.view', 'icon' => 'chart'],
                        ['key' => 'roles', 'route' => null, 'permission' => 'roles.view', 'icon' => 'lock'],
                        ['key' => 'settings', 'route' => null, 'permission' => 'settings.view', 'icon' => 'cog'],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    @if (is_null($item['permission']) || auth()->user()->can($item['permission']))
                        @if ($item['route'] && \Illuminate\Support\Facades\Route::has($item['route']))
                            <a href="{{ route($item['route']) }}"
                                class="nav-item flex items-center gap-3 px-6 py-2.5 text-sm {{ request()->routeIs($item['route'] . '*') ? 'nav-active' : '' }}">
                                <x-admin.icon :name="$item['icon']" />
                                {{ __('admin.nav.' . $item['key']) }}
                            </a>
                        @else
                            <div class="flex items-center justify-between px-6 py-2.5 text-sm text-slate-500">
                                <span class="flex items-center gap-3">
                                    <x-admin.icon :name="$item['icon']" />
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
                            class="{{ app()->getLocale() === 'en' ? 'font-semibold text-[var(--brand-accent)]' : 'text-slate-400' }}">EN</a>
                        <span class="text-slate-300">/</span>
                        <a href="{{ route('admin.locale.switch', 'ar') }}"
                            class="{{ app()->getLocale() === 'ar' ? 'font-semibold text-[var(--brand-accent)]' : 'text-slate-400' }}">AR</a>
                    </div>
                    <span class="text-slate-600">{{ auth()->user()->name }}</span>
                    <x-admin.status-badge variant="info" :label="auth()->user()->getRoleNames()->first() ?? 'no role'" />
                </div>
            </header>

            <main class="flex-1 p-8">
                @if (session('status'))
                    <div
                        class="mb-6 rounded-lg bg-[var(--brand-success-bg)] border border-[var(--brand-success-border)] text-[var(--brand-success)] text-sm px-4 py-3">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>

</html>
