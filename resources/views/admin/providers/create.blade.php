@extends('admin.layout')

@section('title', __('admin.providers.add_title'))
@section('page_title', __('admin.providers.add_title'))

@section('content')
    <div class="max-w-2xl">
        <x-admin.alert variant="danger" :errors="$errors" />

        <form method="POST" action="{{ route('admin.providers.store') }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf

            @include('admin.providers._form', ['provider' => null])

            @can('verify', \App\Models\Provider::class)
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="verify_now" value="1" @checked(old('verify_now')) class="mt-1">
                    <span>
                        <span class="font-medium text-slate-700">{{ __('admin.providers.verify_now') }}</span>
                        <span class="block text-xs text-slate-400">{{ __('admin.providers.verify_now_help') }}</span>
                    </span>
                </label>
            @endcan

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.providers.create_submit') }}</button>
        </form>
    </div>
@endsection
