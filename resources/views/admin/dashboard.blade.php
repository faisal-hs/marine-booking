@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
    <div class="min-h-screen">
        <nav class="bg-white shadow-sm px-6 py-4 flex justify-between items-center">
            <span class="font-semibold">Marine Booking Admin</span>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-600">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-red-600 hover:underline">Logout</button>
                </form>
            </div>
        </nav>

        <div class="p-6">
            <h1 class="text-lg font-semibold mb-4">Modules</h1>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach ($modules as $module)
                    <div class="bg-white rounded-lg shadow-sm p-4 text-center text-sm font-medium text-gray-700">
                        {{ $module['label'] }}
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-400 mt-6">
                Module access is currently unrestricted — permission-based gating arrives with Module 02 (Roles &amp;
                Permissions).
            </p>
        </div>
    </div>
@endsection
