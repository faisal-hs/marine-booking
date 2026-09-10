@extends('public.layout')

@section('title', config('branding.name') . ' — ' . __('public.home.hero_title'))

@section('content')
    {{-- Hero — a CSS gradient stands in for a real marine photograph
         (no image asset pipeline exists yet); swapping in a real photo
         later is a one-line change to this section's background. --}}
    <section class="relative text-white" style="background: linear-gradient(135deg, var(--brand-primary) 0%, #0E5A63 50%, var(--brand-accent) 100%);">
        <div class="max-w-6xl mx-auto px-6 py-20 md:py-28 text-center">
            <h1 class="text-3xl md:text-5xl font-semibold mb-4">{{ __('public.home.hero_title') }}</h1>
            <p class="text-slate-100/90 mb-10 max-w-xl mx-auto">{{ __('public.home.hero_subtitle') }}</p>

            <form method="GET" action="{{ route('public.activities.index') }}"
                class="bg-white rounded-2xl p-3 max-w-3xl mx-auto flex flex-col md:flex-row gap-2 shadow-xl">
                <input type="text" name="location" placeholder="{{ __('public.home.location_placeholder') }}"
                    class="flex-1 rounded-lg px-4 py-3 text-slate-900 text-sm">

                <select name="category_id" class="rounded-lg px-4 py-3 text-slate-600 text-sm md:w-48">
                    <option value="">{{ __('public.activities.all_categories') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>

                <input type="text" name="search" placeholder="{{ __('public.home.search_placeholder') }}"
                    class="flex-1 rounded-lg px-4 py-3 text-slate-900 text-sm">

                <button type="submit" class="bg-[var(--brand-accent)] text-white rounded-lg px-8 py-3 font-medium text-sm whitespace-nowrap">
                    {{ __('public.home.search_button') }}
                </button>
            </form>
        </div>
    </section>

    {{-- Explore Categories --}}
    <section class="max-w-6xl mx-auto px-6 py-14">
        <h2 class="text-xl font-semibold mb-6">{{ __('public.home.categories_title') }}</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            @forelse ($categories as $category)
                @php
                    $icon = match (true) {
                        str_contains(strtolower($category->name), 'div') => '🤿',
                        str_contains(strtolower($category->name), 'snork') => '🐠',
                        str_contains(strtolower($category->name), 'fish') => '🎣',
                        str_contains(strtolower($category->name), 'yacht'), str_contains(strtolower($category->name), 'boat') => '🛥️',
                        str_contains(strtolower($category->name), 'jet') => '🚤',
                        default => '🌊',
                    };
                @endphp
                <a href="{{ route('public.categories.show', $category) }}"
                    class="bg-white rounded-xl border border-slate-200 p-5 text-center hover:border-[var(--brand-accent)] hover:shadow-md transition">
                    <span class="text-3xl block mb-2">{{ $icon }}</span>
                    <p class="font-medium text-slate-800 text-sm">{{ $category->name }}</p>
                </a>
            @empty
                <p class="text-slate-400 col-span-full text-center">{{ __('public.home.no_categories') }}</p>
            @endforelse
        </div>
    </section>

    {{-- Featured Activities --}}
    <section class="max-w-6xl mx-auto px-6 py-14">
        <h2 class="text-xl font-semibold mb-6">{{ __('public.home.featured_title') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @forelse ($featuredActivities as $activity)
                @include('public.partials.featured-activity-card', ['activity' => $activity])
            @empty
                <p class="text-slate-400 col-span-full text-center">{{ __('public.home.no_activities') }}</p>
            @endforelse
        </div>
    </section>

    {{-- Top Rated — only ever shown when at least one activity has reviews --}}
    @if ($topRatedActivities->isNotEmpty())
        <section class="max-w-6xl mx-auto px-6 py-14">
            <h2 class="text-xl font-semibold mb-6">{{ __('public.home.top_rated_title') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach ($topRatedActivities as $activity)
                    @include('public.partials.featured-activity-card', ['activity' => $activity])
                @endforeach
            </div>
        </section>
    @endif

    {{-- Why Choose Us --}}
    <section class="bg-white border-y border-slate-200">
        <div class="max-w-6xl mx-auto px-6 py-14">
            <h2 class="text-xl font-semibold mb-8 text-center">{{ __('public.home.why_us_title') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 text-center">
                <div>
                    <span class="text-3xl block mb-3">✅</span>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_1_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_1_body') }}</p>
                </div>
                <div>
                    <span class="text-3xl block mb-3">🔒</span>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_2_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_2_body') }}</p>
                </div>
                <div>
                    <span class="text-3xl block mb-3">⭐</span>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_3_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_3_body') }}</p>
                </div>
                <div>
                    <span class="text-3xl block mb-3">📱</span>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_4_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_4_body') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- How It Works --}}
    <section class="max-w-6xl mx-auto px-6 py-14">
        <h2 class="text-xl font-semibold mb-8 text-center">{{ __('public.home.how_it_works_title') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            @foreach (['discover', 'choose', 'book', 'enjoy'] as $index => $step)
                <div class="text-center">
                    <div class="w-10 h-10 rounded-full bg-[var(--brand-accent)] text-white flex items-center justify-center mx-auto mb-3 font-semibold">
                        {{ $index + 1 }}
                    </div>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.how_it_works_' . $step . '_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.how_it_works_' . $step . '_body') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Mobile App CTA --}}
    <section id="download-app" class="bg-[var(--brand-primary)] text-white">
        <div class="max-w-6xl mx-auto px-6 py-16 grid grid-cols-1 md:grid-cols-2 gap-10 items-center">
            <div>
                <h2 class="text-2xl font-semibold mb-3">{{ __('public.home.app_cta_title') }}</h2>
                <p class="text-slate-300 mb-6">{{ __('public.home.app_cta_body') }}</p>
                <div class="flex flex-wrap gap-3">
                    <a href="#" class="bg-white text-[var(--brand-primary)] rounded-lg px-5 py-3 text-sm font-medium flex items-center gap-2">
                        <span></span> {{ __('public.home.app_store_button') }}
                    </a>
                    <a href="#" class="bg-white text-[var(--brand-primary)] rounded-lg px-5 py-3 text-sm font-medium flex items-center gap-2">
                        <span>▶</span> {{ __('public.home.google_play_button') }}
                    </a>
                </div>
            </div>
            <div class="flex justify-center">
                {{-- Simple phone-frame visual placeholder — no real app
                     screenshot asset exists yet. --}}
                <div class="w-40 h-80 bg-white/10 border-2 border-white/20 rounded-3xl flex items-center justify-center text-4xl">
                    📱
                </div>
            </div>
        </div>
    </section>
@endsection
