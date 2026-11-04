@extends('admin.layout')

@section('title', __('admin.activities.add_title'))
@section('page_title', __('admin.activities.add_title'))

@section('content')
    <div class="max-w-3xl">
        <x-admin.alert variant="danger" :errors="$errors" />

        <form method="POST" action="{{ route('admin.activities.store') }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf

            <p class="text-xs text-slate-500">{{ __('admin.activities.draft_note') }}</p>

            @include('admin.activities._form', ['activity' => null])

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.activities.create_submit') }}</button>
        </form>
    </div>
@endsection
