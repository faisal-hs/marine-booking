{{--
A plain message banner: a variant and some content, nothing else.

Why this exists next to x-admin.alert: that component also accepts a list of validation
$errors, and its `errors` prop shares its name with the validation-error bag Laravel shares
with every view. With no errors prop passed, the shared (empty) bag is picked up instead
of the default, the component decides there is nothing to show, and a message passed in
the slot is never rendered. This one has no such prop, so what is inside it always shows.

Usage: <x-admin.notice variant="danger">{{ session('error') }}</x-admin.notice>
--}}
@props(['variant' => 'info']) {{-- success | warning | danger | info | neutral --}}

@php
    $variantMap = [
        'success' => 'bg-[var(--brand-success-bg)] border-[var(--brand-success-border)] text-[var(--brand-success)]',
        'warning' => 'bg-[var(--brand-warning-bg)] border-[var(--brand-warning-border)] text-[var(--brand-warning)]',
        'danger' => 'bg-[var(--brand-danger-bg)] border-[var(--brand-danger-border)] text-[var(--brand-danger)]',
        'info' => 'bg-[var(--brand-info-bg)] border-[var(--brand-info-border)] text-[var(--brand-info)]',
        'neutral' => 'bg-[var(--brand-neutral-bg)] border-[var(--brand-neutral-border)] text-[var(--brand-neutral)]',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'mb-4 rounded-lg border text-sm p-3 ' . ($variantMap[$variant] ?? $variantMap['info'])]) }}>
    {{ $slot }}
</div>
