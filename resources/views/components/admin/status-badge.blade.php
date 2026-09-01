{{--
Semantic status badge. Every module has its own domain statuses (users:
active/inactive/suspended, categories: active/inactive, feature
definitions: active/inactive) — this component does NOT know about any
of them. Each view maps its own status to one of five presentation
variants (success/warning/danger/info/neutral) and passes the already-
translated label.

Usage:
<x-admin.status-badge variant="success" label="Active" />
<x-admin.status-badge variant="danger" label="Suspended" icon="x-circle" />
--}}
@props([
    'variant' => 'neutral', // success | warning | danger | info | neutral
    'label',
    'icon' => null,
])

@php
    $variantMap = [
        'success' => 'bg-[var(--brand-success-bg)] text-[var(--brand-success)] border-[var(--brand-success-border)]',
        'warning' => 'bg-[var(--brand-warning-bg)] text-[var(--brand-warning)] border-[var(--brand-warning-border)]',
        'danger' => 'bg-[var(--brand-danger-bg)] text-[var(--brand-danger)] border-[var(--brand-danger-border)]',
        'info' => 'bg-[var(--brand-info-bg)] text-[var(--brand-info)] border-[var(--brand-info-border)]',
        'neutral' => 'bg-[var(--brand-neutral-bg)] text-[var(--brand-neutral)] border-[var(--brand-neutral-border)]',
    ];

    $classes = $variantMap[$variant] ?? $variantMap['neutral'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs border {$classes}"]) }}>
    @if ($icon)
        <x-admin.icon :name="$icon" size="3.5" />
    @endif
    {{ $label }}
</span>
