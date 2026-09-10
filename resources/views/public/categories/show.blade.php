@extends('public.layout')

@section('title', $category->name . ' — ' . config('branding.name'))

@section('content')
    <div class="max-w-6xl mx-auto px-6 py-10">
        <nav class="text-sm text-slate-400 mb-4">
            <a href="{{ route('public.home') }}" class="hover:text-[var(--brand-accent)]">{{ __('public.nav.home') }}</a>
            <span class="mx-1">/</span>
            <span class="text-slate-600">{{ $category->name }}</span>
        </nav>

        <h1 class="text-2xl font-semibold mb-2">{{ $category->name }}</h1>
        @if ($category->description)
            <p class="text-slate-500 mb-8 max-w-2xl">{{ $category->description }}</p>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            @forelse ($activities as $activity)
                @include('public.partials.activity-card', ['activity' => $activity])
            @empty
                <p class="text-slate-400 col-span-full text-center py-12">{{ __('public.category.no_activities') }}</p>
            @endforelse
        </div>

        <div class="mt-8">{{ $activities->links() }}</div>
    </div>
@endsection
