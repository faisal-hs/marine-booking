<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('branding.name'))</title>
    <meta name="description" content="@yield('meta_description', __('public.meta.default_description'))">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    @include('admin.partials.theme-vars')
</head>

<body class="bg-[var(--brand-page-bg)] text-[var(--brand-ink)] antialiased">
    <header class="bg-white border-b border-slate-200 sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between gap-6">
            <a href="{{ route('public.home') }}" class="text-lg font-semibold text-[var(--brand-primary)]">
                {{ config('branding.name') }}
            </a>

            <nav class="hidden md:flex items-center gap-6 text-sm text-slate-600">
                <a href="{{ route('public.home') }}" class="hover:text-[var(--brand-accent)]">{{ __('public.nav.home') }}</a>
                <a href="{{ route('public.activities.index') }}" class="hover:text-[var(--brand-accent)]">{{ __('public.nav.activities') }}</a>
                @foreach ($categories ?? [] as $navCategory)
                    <a href="{{ route('public.categories.show', $navCategory) }}" class="hover:text-[var(--brand-accent)]">
                        {{ $navCategory->name }}
                    </a>
                @endforeach
            </nav>

            <div class="flex items-center gap-3 text-xs">
                @foreach (['en' => 'EN', 'ar' => 'AR'] as $localeCode => $label)
                    <a href="{{ route('public.locale.switch', $localeCode) }}"
                        class="{{ app()->getLocale() === $localeCode ? 'font-semibold text-[var(--brand-accent)]' : 'text-slate-400' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="bg-[var(--brand-primary)] text-slate-300 mt-16">
        <div class="max-w-6xl mx-auto px-6 py-10 text-sm">
            <p class="font-semibold text-white mb-2">{{ config('branding.name') }}</p>
            <p class="text-slate-400">{{ __('public.footer.tagline') }}</p>
        </div>
    </footer>
</body>

</html>
