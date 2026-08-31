@extends('admin.layout')

@section('title', __('admin.users.edit_title'))
@section('page_title', __('admin.users.edit_title'))

@section('content')
    <div class="max-w-lg">
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.update', $user) }}"
            class="bg-white rounded-xl border border-slate-200 p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.dashboard.name') }}</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.email') }}</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.phone') }}</label>
                <input type="text" name="phone" value="{{ old('phone', $user->phone) }}"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2">
            </div>

            @if ($user->isAdmin())
                <div>
                    <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.roles') }}</label>
                    <select name="role" class="w-full border border-slate-200 rounded-lg px-3 py-2">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected($user->hasRole($role))>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($user->isProvider())
                <div>
                    <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.business') }}</label>
                    <input type="text" name="business_name" value="{{ old('business_name', $user->provider?->business_name) }}"
                        class="w-full border border-slate-200 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block font-medium mb-1 text-slate-700">{{ __('admin.users.verification') }}</label>
                    <select name="verification_status" class="w-full border border-slate-200 rounded-lg px-3 py-2">
                        @foreach (['pending', 'verified', 'rejected'] as $status)
                            <option value="{{ $status }}" @selected($user->provider?->verification_status === $status)>
                                {{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <button type="submit"
                class="w-full bg-[#0B2E33] text-white rounded-lg py-2 hover:bg-[#0E3B42] transition">{{ __('admin.users.save_submit') }}</button>
        </form>
    </div>
@endsection
