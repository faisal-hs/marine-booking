@extends('admin.layout')

@section('title', 'User Details')

@section('content')
    <div class="min-h-screen">
        <nav class="bg-white shadow-sm px-6 py-4">
            <a href="{{ route('admin.users.index') }}" class="font-semibold">← Users</a>
        </nav>

        <div class="p-6 max-w-2xl">
            @if (session('status'))
                <div class="mb-4 rounded bg-green-50 border border-green-200 text-green-700 text-sm p-3">{{ session('status') }}
                </div>
            @endif

            <div class="bg-white rounded-lg shadow-sm p-6 space-y-3 text-sm">
                <h1 class="text-lg font-semibold mb-4">{{ $user->name }}</h1>
                <p><span class="text-gray-500">Type:</span> {{ ucfirst($user->user_type) }}</p>
                <p><span class="text-gray-500">Email:</span> {{ $user->email ?? '—' }}</p>
                <p><span class="text-gray-500">Phone:</span> {{ $user->phone ?? '—' }}</p>
                <p><span class="text-gray-500">Status:</span> {{ ucfirst($user->status) }}</p>

                @if ($user->isProvider() && $user->provider)
                    <p><span class="text-gray-500">Business:</span> {{ $user->provider->business_name }}</p>
                    <p><span class="text-gray-500">Verification:</span> {{ ucfirst($user->provider->verification_status) }}</p>
                @endif

                @if ($user->isCustomer() && $user->customer)
                    <p><span class="text-gray-500">Language:</span> {{ $user->customer->preferred_language }}</p>
                @endif

                @if ($user->isAdmin())
                    <p><span class="text-gray-500">Roles:</span> {{ $user->getRoleNames()->implode(', ') ?: '—' }}</p>
                @endif

                <div class="pt-4 flex gap-3">
                    @can('users.update')
                        <a href="{{ route('admin.users.edit', $user) }}"
                            class="text-xs bg-gray-900 text-white rounded px-3 py-2">Edit</a>
                    @endcan

                    @if ($user->id !== auth()->id())
                        @can('users.update')
                            @if ($user->status === 'active')
                                <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="status" value="suspended">
                                    <button class="text-xs bg-red-600 text-white rounded px-3 py-2">Suspend</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="status" value="active">
                                    <button class="text-xs bg-green-600 text-white rounded px-3 py-2">Activate</button>
                                </form>
                            @endif
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
