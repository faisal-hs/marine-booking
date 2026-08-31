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
        body {
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
        }
    </style>
</head>

<body class="bg-[#F7FAFA] text-[#0F172A] antialiased">
    <div
        class="absolute top-4 {{ app()->getLocale() === 'ar' ? 'left-4' : 'right-4' }} flex items-center gap-1 text-xs">
        <a href="{{ route('admin.locale.switch', 'en') }}"
            class="{{ app()->getLocale() === 'en' ? 'font-semibold text-teal-700' : 'text-slate-400' }}">EN</a>
        <span class="text-slate-300">/</span>
        <a href="{{ route('admin.locale.switch', 'ar') }}"
            class="{{ app()->getLocale() === 'ar' ? 'font-semibold text-teal-700' : 'text-slate-400' }}">AR</a>
    </div>

    @yield('content')
</body>

</html>
