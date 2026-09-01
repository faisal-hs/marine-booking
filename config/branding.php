<?php

/*
|--------------------------------------------------------------------------
| Admin Dashboard Branding
|--------------------------------------------------------------------------
|
| Single source of truth for every color, logo, and typography choice used
| by the admin dashboard. Blade views should never hardcode a hex value or
| Tailwind color class directly (e.g. bg-[#0B2E33]) — instead, the shared
| resources/views/admin/partials/theme-vars.blade.php partial exposes each
| color below as a CSS custom property (--brand-*), and views reference
| that variable via Tailwind's arbitrary-value syntax:
|
|     bg-[var(--brand-sidebar)]
|     text-[var(--brand-accent)]
|
| Changing the brand therefore means editing values in this one file, not
| hunting through every Blade view in resources/views/admin/**.
|
*/

return [

    'name' => env('APP_NAME', 'Marine Booking'),

    'tagline' => 'Admin Console',

    /*
    |----------------------------------------------------------------------
    | Logo
    |----------------------------------------------------------------------
    |
    | Paths are relative to the public/ directory (e.g. 'images/logo.svg'),
    | resolved via asset(). Leave null to fall back to a text wordmark
    | (the 'name' value above) — this is the current default, since no
    | binary logo asset exists yet. Swapping in a real logo later is a
    | one-line change here, not a Blade edit.
    |
    */
    'logo' => [
        'light' => env('BRANDING_LOGO_LIGHT'),
        'dark' => env('BRANDING_LOGO_DARK'),
        'favicon' => env('BRANDING_FAVICON'),
    ],

    /*
    |----------------------------------------------------------------------
    | Colors
    |----------------------------------------------------------------------
    |
    | The marine/ocean palette established in the dashboard restyle:
    | deep-sea teal sidebar, turquoise accent for active/interactive
    | states. Semantic status colors (success/warning/danger/info/neutral)
    | are separate from the brand colors so that changing the brand palette
    | never accidentally changes what "success" or "danger" looks like.
    |
    */
    'colors' => [
        'primary' => '#0B2E33',
        'primary_dark' => '#0E3B42',
        'accent' => '#14B8A6',
        'accent_light' => '#5EEAD4',

        'page_bg' => '#F7FAFA',
        'ink' => '#0F172A',

        'sidebar_text' => '#94A3B8',
        'sidebar_text_hover' => '#E2E8F0',

        // Semantic status colors — text/bg/border triplets, used by
        // <x-admin.status-badge> and anywhere a success/warning/danger/
        // info/neutral state needs to render consistently.
        'success' => '#15803D',
        'success_bg' => '#ECFDF5',
        'success_border' => '#BBF7D0',

        'warning' => '#B45309',
        'warning_bg' => '#FEF9E7',
        'warning_border' => '#FDE68A',

        'danger' => '#B91C1C',
        'danger_bg' => '#FEF2F2',
        'danger_border' => '#FECACA',

        'info' => '#4338CA',
        'info_bg' => '#EEF2FF',
        'info_border' => '#C7D2FE',

        'neutral' => '#475569',
        'neutral_bg' => '#F1F5F9',
        'neutral_border' => '#E2E8F0',
    ],

    /*
    |----------------------------------------------------------------------
    | Typography
    |----------------------------------------------------------------------
    |
    | One font, one place. Both admin.layout and admin.guest-layout include
    | the same theme-vars partial rather than each declaring their own
    | <link>/<style> font block — previously they had drifted (one loaded
    | "Plus Jakarta Sans", the other didn't declare a font at all and
    | inherited the default). "Plus Jakarta Sans" was the intentional
    | choice made during the dashboard restyle; the Vite-configured
    | "Instrument Sans" belongs to the still-unused default Laravel
    | scaffold (see Step 26 audit HIGH-3) and is not used here.
    |
    */
    'font' => [
        'family' => 'Plus Jakarta Sans',
        'bunny_family_param' => 'plus-jakarta-sans',
        'weights' => '400,500,600,700',
    ],

];
