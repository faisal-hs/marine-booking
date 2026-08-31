@extends('admin.guest-layout')

@section('title', __('admin.brand'))

@section('content')
    <div class="min-h-screen flex items-center justify-center">
        <div class="w-full max-w-sm bg-white p-8 rounded-xl shadow-sm border border-slate-100">
            <h1 class="text-xl font-semibold mb-6 text-center text-slate-800">{{ __('admin.brand') }}</h1>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium mb-1 text-slate-700">{{ __('admin.auth.email') }}</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1 text-slate-700">{{ __('admin.auth.password') }}</label>
                    <input type="password" name="password" required
                        class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                </div>

                <label class="flex items-center text-sm gap-2 text-slate-600">
                    <input type="checkbox" name="remember">
                    {{ __('admin.auth.remember') }}
                </label>

                <button type="submit"
                    class="w-full bg-[#0B2E33] text-white rounded-lg py-2 text-sm font-medium hover:bg-[#0E3B42] transition">
                    {{ __('admin.auth.submit') }}
                </button>
            </form>
        </div>
    </div>
@endsection
