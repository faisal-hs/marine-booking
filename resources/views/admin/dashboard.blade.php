@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
    <div class="min-h-screen">
        <nav class="bg-white shadow-sm px-6 py-4 flex justify-between items-center">
            <span class="font-semibold">Marine Booking Admin</span>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-600">{{ auth()->user()->name }}</span>
                <span class="text-xs bg-gray-200 rounded px-2 py-1">
                    {{ auth()->user()->getRoleNames()->first() ?? 'no role' }}
                </span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-red-600 hover:underline">Logout</button>
                </form>
            </div>
        </nav>

        <div class="p-6">
            <h1 class="text-lg font-semibold mb-4">Modules</h1>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                @foreach ($modules as $module)
                    @can($module['permission'] . '.view')
                        <div class="bg-white rounded-lg shadow-sm p-4 text-center text-sm font-medium text-gray-700">
                            <span class="text-lg block mb-1">{{ $module['icon'] }}</span>
                            {{ $module['label'] }}
                        </div>
                    @endcan
                @endforeach
            </div>

            {{-- Demo panel: shows the same @can gating pattern applied to row-level
            action buttons, which every real module's CRUD view will follow
            once built (Module 04 onward). --}}
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-sm font-semibold">Users (permission demo)</h2>
                    @can('users.create')
                        <button class="text-xs bg-gray-900 text-white rounded px-3 py-1">+ Add User</button>
                    @endcan
                </div>

                @can('users.view')
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2">Name</th>
                                <th class="py-2">Role</th>
                                <th class="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b">
                                <td class="py-2">Sample User</td>
                                <td class="py-2">customer</td>
                                <td class="py-2 text-right space-x-2">
                                    @can('users.update')
                                        <button class="text-xs text-blue-600">Edit</button>
                                    @endcan
                                    @can('users.delete')
                                        <button class="text-xs text-red-600">Delete</button>
                                    @endcan
                                </td>
                            </tr>
                        </tbody>
                    </table>
                @else
                    <p class="text-xs text-gray-400">You don't have permission to view users.</p>
                @endcan
            </div>

            <p class="text-xs text-gray-400 mt-6">
                Real CRUD screens for each module ship as their backing modules are built
                (Categories → Module 04, Activities → Module 05, etc.) — this dashboard
                already enforces the correct permission gating for whatever ships into it.
            </p>
        </div>
    </div>
@endsection
