@extends('admin.layout')

@section('title', __('admin.notifications.edit_title'))
@section('page_title', __('admin.notifications.edit_title'))

@section('content')
    @include('admin.notifications._tabs')

    <div class="max-w-2xl">
        <x-admin.alert variant="danger" :errors="$errors" />

        <form method="POST" action="{{ route('admin.notifications.broadcasts.update', $broadcast) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')

            @include('admin.notifications.broadcasts._form', ['broadcast' => $broadcast])

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.notifications.save_submit') }}</button>
        </form>
    </div>
@endsection
