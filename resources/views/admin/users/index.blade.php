@extends('admin.layout')

@section('title', 'Users')

@section('content')
    <div class="min-h-screen">
        <nav class="bg-white shadow-sm px-6 py-4 flex justify-between items-center">
            <a href="{{ route('admin.dashboard') }}" class="font-semibold">← Marine Booking Admin</a>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-600">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">@csrf<button
                        class="text-red-600 hover:underline">Logout</button></form>
            </div>
        </nav>

        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-lg font-semibold">Users</h1>
                @can('users.create')
                    <a href="{{ route('admin.users.create') }}" class="text-xs bg-gray-900 text-white rounded px-3 py-2">+ Add
                        User</a>
                @endcan
            </div>

            <form method="GET" class="flex gap-2 mb-4 text-sm">
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                    placeholder="Search name/email/phone" class="border border-gray-300 rounded px-3 py-1.5">
                <select name="user_type" class="border border-gray-300 rounded px-3 py-1.5">
                    <option value="">All types</option>
                    @foreach (['admin', 'provider', 'customer'] as $type)
                        <option value="{{ $type }}" @selected(($filters['user_type'] ?? '') === $type)>{{ ucfirst($type) }}
                        </option>
                    @endforeach
                </select>
                <select name="status" class="border border-gray-300 rounded px-3 py-1.5">
                    <option value="">All statuses</option>
                    @foreach (['active', 'inactive', 'suspended'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="bg-gray-200 rounded px-3 py-1.5">Filter</button>
            </form>

            @if (session('status'))
                <div class="mb-4 rounded bg-green-50 border border-green-200 text-green-700 text-sm p-3">{{ session('status') }}
                </div>
            @endif

            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b bg-gray-50">
                            <th class="py-2 px-4">Name</th>
                            <th class="py-2 px-4">Type</th>
                            <th class="py-2 px-4">Contact</th>
                            <th class="py-2 px-4">Status</th>
                            <th class="py-2 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr class="border-b">
                                <td class="py-2 px-4">{{ $user->name }}</td>
                                <td class="py-2 px-4">{{ ucfirst($user->user_type) }}</td>
                                <td class="py-2 px-4">{{ $user->email ?? $user->phone ?? '—' }}</td>
                                <td class="py-2 px-4">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-xs',
                                        'bg-green-100 text-green-700' => $user->status === 'active',
                                        'bg-gray-100 text-gray-600' => $user->status === 'inactive',
                                        'bg-red-100 text-red-700' => $user->status === 'suspended',
                                    ])>{{ ucfirst($user->status) }}</span>
                                </td>
                                <td class="py-2 px-4 text-right space-x-2">
                                    @can('users.view')
                                        <a href="{{ route('admin.users.show', $user) }}" class="text-blue-600 text-xs">View</a>
                                    @endcan
                                    @can('users.update')
                                        <a href="{{ route('admin.users.edit', $user) }}" class="text-blue-600 text-xs">Edit</a>
                                    @endcan
                                    @can('users.delete')
                                        @if ($user->id !== auth()->id())
                                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="inline"
                                                onsubmit="return confirm('Delete this user?')">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 text-xs">Delete</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $users->links() }}</div>
        </div>
    </div>
@endsection
