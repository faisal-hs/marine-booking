{{--
Single source of truth for admin dashboard theming — included by both
admin.layout and admin.guest-layout so there is exactly one place that
loads the brand font and exposes brand colors as CSS custom properties.

Every value here is read from config/branding.php. To rebrand the
dashboard (colors, font), edit that file — never hardcode a hex value
or a second font
<link> directly in a layout.
--}}
@php
    $brandColors = config('branding.colors');
    $font = config('branding.font');
@endphp

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family={{ $font['bunny_family_param'] }}:{{ $font['weights'] }}&display=swap"
    rel="stylesheet" />

<style>
    :root {
        --brand-primary:
            {{ $brandColors['primary'] }}
        ;
        --brand-primary-dark:
            {{ $brandColors['primary_dark'] }}
        ;
        --brand-accent:
            {{ $brandColors['accent'] }}
        ;
        --brand-accent-light:
            {{ $brandColors['accent_light'] }}
        ;
        --brand-page-bg:
            {{ $brandColors['page_bg'] }}
        ;
        --brand-ink:
            {{ $brandColors['ink'] }}
        ;
        --brand-sidebar-text:
            {{ $brandColors['sidebar_text'] }}
        ;
        --brand-sidebar-text-hover:
            {{ $brandColors['sidebar_text_hover'] }}
        ;

        --brand-success:
            {{ $brandColors['success'] }}
        ;
        --brand-success-bg:
            {{ $brandColors['success_bg'] }}
        ;
        --brand-success-border:
            {{ $brandColors['success_border'] }}
        ;

        --brand-warning:
            {{ $brandColors['warning'] }}
        ;
        --brand-warning-bg:
            {{ $brandColors['warning_bg'] }}
        ;
        --brand-warning-border:
            {{ $brandColors['warning_border'] }}
        ;

        --brand-danger:
            {{ $brandColors['danger'] }}
        ;
        --brand-danger-bg:
            {{ $brandColors['danger_bg'] }}
        ;
        --brand-danger-border:
            {{ $brandColors['danger_border'] }}
        ;

        --brand-info:
            {{ $brandColors['info'] }}
        ;
        --brand-info-bg:
            {{ $brandColors['info_bg'] }}
        ;
        --brand-info-border:
            {{ $brandColors['info_border'] }}
        ;

        --brand-neutral:
            {{ $brandColors['neutral'] }}
        ;
        --brand-neutral-bg:
            {{ $brandColors['neutral_bg'] }}
        ;
        --brand-neutral-border:
            {{ $brandColors['neutral_border'] }}
        ;
    }

    body {
        font-family: '{{ $font['family'] }}', ui-sans-serif, system-ui, sans-serif;
    }

    th,
    td {
        text-align: start !important;
    }

    .sidebar-bg {
        background: linear-gradient(180deg, var(--brand-primary) 0%, var(--brand-primary-dark) 100%);
    }

    .nav-active {
        background: color-mix(in srgb, var(--brand-accent) 15%, transparent);
        color: var(--brand-accent-light);
        border-right: 3px solid var(--brand-accent);
    }

    html[dir="rtl"] .nav-active {
        border-right: none;
        border-left: 3px solid var(--brand-accent);
    }

    .nav-item {
        color: var(--brand-sidebar-text);
    }

    .nav-item:hover {
        background: rgba(255, 255, 255, 0.05);
        color: var(--brand-sidebar-text-hover);
    }
</style>
