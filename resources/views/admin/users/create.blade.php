@extends('admin.layout')

@section('title', 'Add User')

@section('content')
    <div class="min-h-screen">
        <nav class="bg-white shadow-sm px-6 py-4">
            <a href="{{ route('admin.users.index') }}" class="font-semibold">← Users</a>
        </nav>

        <div class="p-6 max-w-lg">
            @if ($errors->any())
                <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 text-sm p-3">
                    @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.store') }}"
                class="bg-white rounded-lg shadow-sm p-6 space-y-4 text-sm">
                @csrf

                <div>
                    <label class="block font-medium mb-1">User Type</label>
                    <select name="user_type" required class="w-full border border-gray-300 rounded px-3 py-2">
                        <option value="admin">Admin</option>
                        <option value="provider">Provider</option>
                        <option value="customer">Customer</option>
                    </select>
                </div>

                <div>
                    <label class="block font-medium mb-1">Name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <div>
                    <label class="block font-medium mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}"
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <div>
                    <label class="block font-medium mb-1">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone') }}"
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <div>
                    <label class="block font-medium mb-1">Password (admin only)</label>
                    <input type="password" name="password" class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <div>
                    <label class="block font-medium mb-1">Role (admin only)</label>
                    <select name="role" class="w-full border border-gray-300 rounded px-3 py-2">
                        <option value="">—</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role }}">{{ $role }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block font-medium mb-1">Business Name (provider only)</label>
                    <input type="text" name="business_name" value="{{ old('business_name') }}"
                        class="w-full border border-gray-300 rounded px-3 py-2">
                </div>

                <button type="submit" class="w-full bg-gray-900 text-white rounded py-2">Create User</button>
            </form>
        </div>
    </div>
@endsection
