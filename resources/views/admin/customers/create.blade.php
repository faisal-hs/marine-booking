@extends('admin.layout')

@section('title', __('admin.customers.add_title'))
@section('page_title', __('admin.customers.add_title'))

@section('content')
    <div class="max-w-2xl">
        <x-admin.alert variant="danger" :errors="$errors" />

        <form method="POST" action="{{ route('admin.customers.store') }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf

            @include('admin.customers._form', ['customer' => null])

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.customers.create_submit') }}</button>
        </form>
    </div>
@endsection
