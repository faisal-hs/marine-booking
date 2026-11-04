{{--
Shows who an account is, and flags it when the account was deleted. Admin
screens keep showing the name on reservations, payments and reviews of a
deleted customer or provider (the history is still real), so the marker is what
tells the reader the person is no longer an active account.

Usage: <x-admin.account-name :user="$reservation->customer->user" />
--}}
@props(['user'])

<span>{{ $user->name }}</span>
@if ($user->trashed())
    <x-admin.status-badge variant="neutral" :label="__('admin.accounts.deleted')" />
@endif
