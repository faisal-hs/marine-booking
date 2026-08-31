@extends('admin.layout')

@section('title', 'Edit User')

@section('content')
    <div class="min-h-screen">
        <nav class="bg-white shadow-sm px-6 py-4">
            <a href="{{ route('admin.users.show', $user) }}" class="font-semibold">← {{ $user->name }}</a>
        </nav>

        <div class="p-6 max-w-lg">
            @if ($errors->any())
                <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                    @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.update', $user) }}"
                class="bg-white rounded-lg shadow-sm p-6 space-y-4 text-sm">
                @csrf
                @method('PUT')

                <div>
                    <label class="block font-medium mb-1">Name</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}"
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <div>
                    <label class="block font-medium mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}"
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <div>
                    <label class="block font-medium mb-1">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $user->phone) }}"
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                @if ($user->isAdmin())
                    <div>
                        <label class="block font-medium mb-1">Role</label>
                        <select name="role" class="w-full border border-gray-300 rounded px-3 py-2">
                            @foreach ($roles as $role)
                                <option value="{{ $role }}" @selected($user->hasRole($role))>{{ $role }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($user->isProvider())
                    <div>
                        <label class="block font-medium mb-1">Business Name</label>
                        <input type="text" name="business_name"
                            value="{{ old('business_name', $user->provider?->business_name) }}"
                            class="w-full border border-gray-300 rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Verification Status</label>
                        <select name="verification_status" class="w-full border border-gray-300 rounded px-3 py-2">
                            @foreach (['pending', 'verified', 'rejected'] as $status)
                                <option value="{{ $status }}" @selected($user->provider?->verification_status === $status)>
                                    {{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button type="submit" class="w-full bg-gray-900 text-white rounded py-2">Save Changes</button>
            </form>
        </div>
    </div>
@endsection
