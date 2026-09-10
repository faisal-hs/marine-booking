@extends('public.layout')

@section('title', __('public.activities.title') . ' — ' . config('branding.name'))

@section('content')
    <div class="max-w-6xl mx-auto px-6 py-10">
        <h1 class="text-2xl font-semibold mb-6">{{ __('public.activities.title') }}</h1>

        <form method="GET" class="flex flex-wrap gap-2 mb-8 text-sm">
            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                placeholder="{{ __('public.activities.search_placeholder') }}"
                class="border border-slate-200 rounded-lg px-3 py-2 flex-1 min-w-[200px]">

            <select name="category_id" class="border border-slate-200 rounded-lg px-3 py-2">
                <option value="">{{ __('public.activities.all_categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? null) == $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>

            <select name="sort" class="border border-slate-200 rounded-lg px-3 py-2">
                <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>
                    {{ __('public.activities.sort_newest') }}</option>
                <option value="rating" @selected(($filters['sort'] ?? '') === 'rating')>
                    {{ __('public.activities.sort_rating') }}</option>
            </select>

            <button type="submit" class="bg-[var(--brand-accent)] text-white rounded-lg px-5 py-2">
                {{ __('public.activities.apply_filters') }}
            </button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            @forelse ($activities as $activity)
                @include('public.partials.activity-card', ['activity' => $activity])
            @empty
                <p class="text-slate-400 col-span-full text-center py-12">{{ __('public.activities.no_results') }}</p>
            @endforelse
        </div>

        <div class="mt-8">{{ $activities->links() }}</div>
    </div>
@endsection
