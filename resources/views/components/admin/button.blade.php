{{--
Centralized button. Supports the two visual patterns already
established across the dashboard rather than inventing a third:

- solid (default): the dark "+ Add X" / form-submit buttons
- pill: the small rounded action buttons in table rows
(View / Edit / Delete)

Renders as <a> when href is given, otherwise as <button>.

        Usage:
        <x-admin.button href="{{ route('admin.categories.create') }}">+ Add Category</x-admin.button>
        <x-admin.button type="submit">Save Changes</x-admin.button>
        <x-admin.button pill variant="danger" type="submit" icon="trash">Delete</x-admin.button>
        <x-admin.button pill href="{{ route('admin.users.edit', $user) }}" variant="info"
            icon="edit">Edit</x-admin.button>
        --}}
        @props([
            'href' => null,
            'type' => 'button',
            'variant' => 'primary', // primary | secondary | danger | info | neutral
            'pill' => false,
            'icon' => null,
        ])

        @php
            $solidVariants = [
                'primary' => 'bg-[var(--brand-primary)] text-white hover:bg-[var(--brand-primary-dark)]',
                'secondary' => 'bg-slate-100 text-slate-700 hover:bg-slate-200',
                'danger' => 'bg-[var(--brand-danger)] text-white hover:opacity-90',
            ];

            $pillVariants = [
                'neutral' => 'bg-[var(--brand-neutral-bg)] text-[var(--brand-neutral)] hover:opacity-80',
                'info' => 'bg-[var(--brand-info-bg)] text-[var(--brand-info)] hover:opacity-80',
                'danger' => 'bg-[var(--brand-danger-bg)] text-[var(--brand-danger)] hover:opacity-80',
                'success' => 'bg-[var(--brand-success-bg)] text-[var(--brand-success)] hover:opacity-80',
            ];

            $classes = $pill
                ? 'inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs transition ' . ($pillVariants[$variant] ?? $pillVariants['neutral'])
                : 'inline-flex items-center justify-center gap-1.5 text-xs rounded-lg px-4 py-2 transition ' . ($solidVariants[$variant] ?? $solidVariants['primary']);
        @endphp

        @if ($href)
            <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
                @if ($icon)
                    <x-admin.icon :name="$icon" size="3" />
                @endif
                {{ $slot }}
            </a>
        @else
            <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
                @if ($icon)
                    <x-admin.icon :name="$icon" size="3" />
                @endif
                {{ $slot }}
            </button>
        @endif
