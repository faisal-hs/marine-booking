{{--
A button that submits a POST/PUT/DELETE form, optionally asking "are you sure?" first.
For the row actions that change something (suspend, delete, restore ...).

Usage:
<x-admin.confirm-form :action="route('admin.providers.destroy', $provider)" method="DELETE"
    :confirm="__('admin.providers.confirm_delete')" variant="danger" icon="trash">{{ __('admin.providers.delete') }}</x-admin.confirm-form>
--}}
@props([
    'action',
    'method' => 'POST',
    'confirm' => null,
    'variant' => 'neutral', // neutral | info | danger | success
    'icon' => null,
    'pill' => true,
])

<form method="POST" action="{{ $action }}" class="inline" @if ($confirm) onsubmit="return confirm(@js($confirm))" @endif>
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif
    <x-admin.button :pill="$pill" :variant="$variant" :icon="$icon" type="submit">{{ $slot }}</x-admin.button>
</form>
