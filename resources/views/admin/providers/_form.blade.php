{{-- Shared by create and edit. $provider is null when creating. --}}
@php
    $owner = $provider?->user;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <x-admin.field name="name" :label="__('admin.providers.owner_name')" :value="$owner?->name" required />
    <x-admin.field name="phone" :label="__('admin.users.phone')" :value="$owner?->phone" required
        :help="__('admin.providers.phone_help')" />
    <x-admin.field name="email" type="email" :label="__('admin.users.email')" :value="$owner?->email" />
</div>

<hr class="border-slate-100">

<x-admin.field name="business_name" :label="__('admin.providers.business_name')" :value="$provider?->business_name" required />
<x-admin.field name="business_license" :label="__('admin.providers.business_license')" :value="$provider?->business_license" />
<x-admin.field name="bio" type="textarea" rows="4" :label="__('admin.providers.bio')" :value="$provider?->bio" />
<x-admin.field name="address" :label="__('admin.providers.address')" :value="$provider?->address" />

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <x-admin.field name="city" :label="__('admin.providers.city')" :value="$provider?->city" />
    <x-admin.field name="area" :label="__('admin.providers.area')" :value="$provider?->area" />
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <x-admin.field name="lat" type="number" step="any" min="-90" max="90" :label="__('admin.providers.lat')" :value="$provider?->lat" />
    <x-admin.field name="lng" type="number" step="any" min="-180" max="180" :label="__('admin.providers.lng')" :value="$provider?->lng" />
    <x-admin.field name="commission_rate" type="number" step="0.01" min="0" max="100"
        :label="__('admin.providers.commission_rate')" :value="$provider?->commission_rate" />
</div>
