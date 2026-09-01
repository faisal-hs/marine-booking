{{--
    Centralized alert banner. Replaces an identical validation-error banner
    block that was duplicated verbatim across 7 admin create/edit views and
    the login page.

    Usage:
        <x-admin.alert variant="danger">
            <p>Something went wrong.</p>
        </x-admin.alert>

    Shortcut for the common "list of $errors" case:
        <x-admin.alert variant="danger" :errors="$errors" />
--}}
@props([
    'variant' => 'info', // success | warning | danger | info | neutral
    'errors' => null,
])

@php
    $variantMap = [
        'success' => 'bg-[var(--brand-success-bg)] border-[var(--brand-success-border)] text-[var(--brand-success)]',
        'warning' => 'bg-[var(--brand-warning-bg)] border-[var(--brand-warning-border)] text-[var(--brand-warning)]',
        'danger' => 'bg-[var(--brand-danger-bg)] border-[var(--brand-danger-border)] text-[var(--brand-danger)]',
        'info' => 'bg-[var(--brand-info-bg)] border-[var(--brand-info-border)] text-[var(--brand-info)]',
        'neutral' => 'bg-[var(--brand-neutral-bg)] border-[var(--brand-neutral-border)] text-[var(--brand-neutral)]',
    ];

    $classes = $variantMap[$variant] ?? $variantMap['info'];
    $shouldRender = $errors ? $errors->any() : true;
@endphp

@if ($shouldRender)
    <div {{ $attributes->merge(['class' => "mb-4 rounded-lg border text-sm p-3 {$classes}"]) }}>
        @if ($errors)
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </div>
@endif
