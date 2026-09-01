@extends('admin.layout')

@section('title', __('admin.users.add_title'))
@section('page_title', __('admin.users.add_title'))

@section('content')
    <div class="max-w-lg">
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.store') }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.user_type') }}</label>
                <select name="user_type" required class="w-full border border-slate-200 rounded-lg px-3 py-2">
                    <option value="admin">Admin</option>
                    <option value="provider">Provider</option>
                    <option value="customer">Customer</option>
                </select>
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.dashboard.name') }}</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.email') }}</label>
                <input type="email" name="email" value="{{ old('email') }}"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.phone') }}</label>
                <input type="text" name="phone" value="{{ old('phone') }}"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.password') }}</label>
                <input type="password" name="password" class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.role') }}</label>
                <select name="role" class="w-full border border-slate-200 rounded-lg px-3 py-2">
                    <option value="">—</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}">{{ $role }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.business_name') }}</label>
                <input type="text" name="business_name" value="{{ old('business_name') }}"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.users.create_submit') }}</button>
        </form>
    </div>
@endsection
