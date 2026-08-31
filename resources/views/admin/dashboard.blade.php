@extends('admin.layout')

@section('title', __('admin.dashboard.title'))
@section('page_title', __('admin.dashboard.title'))

@section('content')
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-[#ECFDF5] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['total_users'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.total_users') }}</p>
        </div>
        <div class="bg-[#EFF6FF] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['total_customers'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.customers') }}</p>
        </div>
        <div class="bg-[#FEF9E7] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['total_providers'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.providers') }}</p>
        </div>
        <div class="bg-[#FEF2F2] rounded-xl p-5">
            <p class="text-2xl font-semibold text-slate-800">{{ $stats['pending_verifications'] }}</p>
            <p class="text-xs text-slate-600 mt-1">{{ __('admin.dashboard.pending_verification') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.dashboard.recent_users') }}</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 font-medium">{{ __('admin.dashboard.name') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.dashboard.type') }}</th>
                        <th class="py-2 font-medium">{{ __('admin.dashboard.joined') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentUsers as $user)
                        <tr class="border-b border-slate-50">
                            <td class="py-2.5">{{ $user->name }}</td>
                            <td class="py-2.5 text-slate-500">{{ ucfirst($user->user_type) }}</td>
                            <td class="py-2.5 text-slate-400">{{ $user->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 text-slate-400 text-center">{{ __('admin.dashboard.no_users') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="sidebar-bg rounded-xl p-6 text-white">
            <h2 class="text-sm font-semibold mb-2">{{ __('admin.dashboard.verification_queue') }}</h2>
            <p class="text-3xl font-semibold text-teal-300">{{ $stats['pending_verifications'] }}</p>
            <p class="text-xs text-slate-400 mt-1">{{ __('admin.dashboard.waiting_review') }}</p>
            @can('users.view')
                <a href="{{ route('admin.users.index', ['user_type' => 'provider']) }}"
                    class="inline-block mt-4 text-xs bg-teal-500/20 text-teal-300 rounded px-3 py-2 hover:bg-teal-500/30 transition">
                    {{ __('admin.dashboard.review_providers') }} →
                </a>
            @endcan
        </div>
    </div>
@endsection
