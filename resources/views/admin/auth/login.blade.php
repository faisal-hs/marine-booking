@extends('admin.layout')

@section('title', 'Admin Login')

@section('content')
    <div class="min-h-screen flex items-center justify-center">
        <div class="w-full max-w-sm bg-white p-8 rounded-lg shadow-md">
            <h1 class="text-xl font-semibold mb-6 text-center">Marine Booking Admin</h1>

            @if ($errors->any())
                <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" name="password" required
                        class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                </div>

                <label class="flex items-center text-sm gap-2">
                    <input type="checkbox" name="remember">
                    Remember me
                </label>

                <button type="submit"
                    class="w-full bg-gray-900 text-white rounded py-2 text-sm font-medium hover:bg-gray-800">
                    Log in
                </button>
            </form>
        </div>
    </div>
@endsection
