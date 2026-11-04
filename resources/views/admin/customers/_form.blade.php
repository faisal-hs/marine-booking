{{-- Shared by create and edit. $customer is null when creating. --}}
@php
    $account = $customer?->user;
@endphp

<x-admin.field name="name" :label="__('admin.dashboard.name')" :value="$account?->name" required />

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <x-admin.field name="phone" :label="__('admin.users.phone')" :value="$account?->phone" required
        :help="__('admin.customers.phone_help')" />
    <x-admin.field name="email" type="email" :label="__('admin.users.email')" :value="$account?->email" />
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <x-admin.field name="date_of_birth" type="date" :label="__('admin.customers.date_of_birth')"
        :value="$customer?->date_of_birth?->toDateString()" />

    <div>
        <label for="preferred_language" class="block font-medium mb-1 text-slate-700">{{ __('admin.customers.language') }}</label>
        <select id="preferred_language" name="preferred_language" class="w-full border border-slate-200 rounded-lg px-3 py-2">
            @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $label)
                <option value="{{ $code }}" @selected(old('preferred_language', $customer?->preferred_language ?? 'en') === $code)>{{ $label }}</option>
            @endforeach
        </select>
        @error('preferred_language')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
</div>
