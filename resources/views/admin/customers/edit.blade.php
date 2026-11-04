@extends('admin.layout')

@section('title', __('admin.customers.edit_title'))
@section('page_title', __('admin.customers.edit_title'))

@section('content')
    <div class="max-w-2xl">
        <x-admin.alert variant="danger" :errors="$errors" />

        <form method="POST" action="{{ route('admin.customers.update', $customer) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')

            @include('admin.customers._form', ['customer' => $customer])

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.customers.save_submit') }}</button>
        </form>
    </div>
@endsection
