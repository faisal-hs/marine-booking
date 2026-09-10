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
    <header class="bg-white border-b border-slate-200 sticky top-0 z-20">
        <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between gap-6">
            <a href="{{ route('public.home') }}" class="text-lg font-semibold text-[var(--brand-primary)] shrink-0">
                {{ config('branding.name') }}
            </a>

            <nav class="hidden md:flex items-center gap-6 text-sm text-slate-600">
                <a href="{{ route('public.activities.index') }}"
                    class="hover:text-[var(--brand-accent)]">{{ __('public.nav.explore') }}</a>

                {{-- Native <details>/<summary> dropdown — no JS dependency needed. --}}
                        <details class="relative">
                            <summary
                                class="list-none cursor-pointer hover:text-[var(--brand-accent)] flex items-center gap-1">
                                {{ __('public.nav.categories') }}
                                <span class="text-[10px]">▾</span>
                            </summary>
                            <div
                                class="absolute {{ app()->getLocale() === 'ar' ? 'right-0' : 'left-0' }} mt-2 bg-white border border-slate-200 rounded-lg shadow-lg py-2 min-w-[180px]">
                                @forelse ($categories ?? [] as $navCategory)
                                    <a href="{{ route('public.categories.show', $navCategory) }}"
                                        class="block px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-[var(--brand-accent)]">
                                        {{ $navCategory->name }}
                                    </a>
                                @empty
                                    <span
                                        class="block px-4 py-2 text-sm text-slate-400">{{ __('public.home.no_categories') }}</span>
                                @endforelse
                            </div>
                        </details>

                        <a href="{{ route('public.about') }}"
                            class="hover:text-[var(--brand-accent)]">{{ __('public.nav.about') }}</a>
            </nav>

            <div class="flex items-center gap-4 text-sm">
                <div class="flex items-center gap-2 text-xs">
                    @foreach (['en' => 'EN', 'ar' => 'AR'] as $localeCode => $label)
                        <a href="{{ route('public.locale.switch', $localeCode) }}"
                            class="{{ app()->getLocale() === $localeCode ? 'font-semibold text-[var(--brand-accent)]' : 'text-slate-400' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <a href="#download-app"
                    class="bg-[var(--brand-accent)] text-white rounded-lg px-4 py-2 text-sm font-medium whitespace-nowrap">
                    {{ __('public.nav.open_app') }}
                </a>
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="bg-[var(--brand-primary)] text-slate-300">
        <div class="max-w-6xl mx-auto px-6 py-12 grid grid-cols-2 md:grid-cols-5 gap-8 text-sm">
            <div class="col-span-2 md:col-span-1">
                <p class="font-semibold text-white mb-2">{{ config('branding.name') }}</p>
                <p class="text-slate-400 text-xs">{{ __('public.footer.tagline') }}</p>
            </div>

            <div>
                <p class="font-medium text-white mb-3">{{ __('public.footer.categories_heading') }}</p>
                <ul class="space-y-2">
                    @foreach (($categories ?? [])->take(6) as $footerCategory)
                        <li><a href="{{ route('public.categories.show', $footerCategory) }}"
                                class="hover:text-white">{{ $footerCategory->name }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <p class="font-medium text-white mb-3">{{ __('public.footer.explore_heading') }}</p>
                <ul class="space-y-2">
                    <li><a href="{{ route('public.activities.index') }}"
                            class="hover:text-white">{{ __('public.nav.explore') }}</a></li>
                    <li><a href="{{ route('public.activities.index', ['sort' => 'rating']) }}"
                            class="hover:text-white">{{ __('public.home.top_rated_title') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="font-medium text-white mb-3">{{ __('public.footer.company_heading') }}</p>
                <ul class="space-y-2">
                    <li><a href="{{ route('public.about') }}" class="hover:text-white">{{ __('public.nav.about') }}</a>
                    </li>
                    <li><a href="#" class="hover:text-white">{{ __('public.footer.support') }}</a></li>
                    <li><a href="#" class="hover:text-white">{{ __('public.footer.privacy') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="font-medium text-white mb-3">{{ __('public.footer.follow_heading') }}</p>
                <div class="flex gap-3">
                    <a href="#" aria-label="Instagram" class="hover:text-white">Instagram</a>
                </div>
                <div class="flex gap-3 mt-1">
                    <a href="#" aria-label="Facebook" class="hover:text-white">Facebook</a>
                </div>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="max-w-6xl mx-auto px-6 py-4 text-xs text-slate-500">
                &copy; {{ now()->year }} {{ config('branding.name') }}. {{ __('public.footer.rights') }}
            </div>
        </div>
    </footer>
</body>

</html>
