{{--
    Single source of truth for the admin brand mark. Reads from
    config/branding.php — no page should hardcode the brand name or an
    <img> path independently.

    Falls back to a text wordmark when no logo asset path is configured
    (the current state — no binary logo asset exists yet). Swapping in a
    real logo later means setting BRANDING_LOGO_LIGHT/_DARK in .env, not
    editing any Blade file.

    Usage: light variant for the dark sidebar, dark variant for light
    backgrounds such as the guest login card.
        <x-admin.logo />
        <x-admin.logo variant="dark" />
--}}
@props([
    'variant' => 'light',
])

@php
    $logoPath = config("branding.logo.{$variant}");
    $name = config('branding.name');
    $tagline = config('branding.tagline');
@endphp

@if ($logoPath)
    <img src="{{ asset($logoPath) }}" alt="{{ $name }}" {{ $attributes->merge(['class' => 'h-8 w-auto']) }}>
@else
    <div {{ $attributes }}>
        <p @class([
            'font-semibold tracking-tight',
            'text-white' => $variant === 'light',
            'text-slate-800' => $variant === 'dark',
        ])>{{ $name }}</p>
        @if ($tagline)
            <p @class([
                'text-[11px] mt-0.5',
                'text-[var(--brand-accent-light)]/70' => $variant === 'light',
                'text-slate-400' => $variant === 'dark',
            ])>{{ $tagline }}</p>
        @endif
    </div>
@endif
