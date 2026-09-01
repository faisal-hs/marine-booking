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
    <div class="absolute top-4 end-4 flex items-center gap-1 text-xs">
        <a href="{{ route('admin.locale.switch', 'en') }}"
            class="{{ app()->getLocale() === 'en' ? 'font-semibold text-[var(--brand-accent)]' : 'text-slate-400' }}">EN</a>
        <span class="text-slate-300">/</span>
        <a href="{{ route('admin.locale.switch', 'ar') }}"
            class="{{ app()->getLocale() === 'ar' ? 'font-semibold text-[var(--brand-accent)]' : 'text-slate-400' }}">AR</a>
    </div>

    @yield('content')
</body>

</html>
