@extends('public.layout')

@section('title', config('branding.name') . ' — ' . __('public.home.hero_title'))

@section('content')
    <section class="bg-[var(--brand-primary)] text-white">
        <div class="max-w-6xl mx-auto px-6 py-16 text-center">
            <h1 class="text-3xl md:text-4xl font-semibold mb-4">{{ __('public.home.hero_title') }}</h1>
            <p class="text-slate-300 mb-8 max-w-xl mx-auto">{{ __('public.home.hero_subtitle') }}</p>

            <form method="GET" action="{{ route('public.activities.index') }}" class="max-w-xl mx-auto flex gap-2">
                <input type="text" name="search" placeholder="{{ __('public.home.search_placeholder') }}"
                    class="flex-1 rounded-lg px-4 py-3 text-slate-900">
                <button type="submit" class="bg-[var(--brand-accent)] text-white rounded-lg px-6 py-3 font-medium">
                    {{ __('public.home.search_button') }}
                </button>
            </form>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-6 py-12">
        <h2 class="text-xl font-semibold mb-6">{{ __('public.home.categories_title') }}</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @forelse ($categories as $category)
                <a href="{{ route('public.categories.show', $category) }}"
                    class="bg-white rounded-xl border border-slate-200 p-5 text-center hover:border-[var(--brand-accent)] transition">
                    <p class="font-medium text-slate-800">{{ $category->name }}</p>
                </a>
            @empty
                <p class="text-slate-400 col-span-full text-center">{{ __('public.home.no_categories') }}</p>
            @endforelse
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-6 py-12">
        <h2 class="text-xl font-semibold mb-6">{{ __('public.home.featured_title') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            @forelse ($featuredActivities as $activity)
                @include('public.partials.activity-card', ['activity' => $activity])
            @empty
                <p class="text-slate-400 col-span-full text-center">{{ __('public.home.no_activities') }}</p>
            @endforelse
        </div>
    </section>

    @if ($topRatedActivities->isNotEmpty())
        <section class="max-w-6xl mx-auto px-6 py-12">
            <h2 class="text-xl font-semibold mb-6">{{ __('public.home.top_rated_title') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                @foreach ($topRatedActivities as $activity)
                    @include('public.partials.activity-card', ['activity' => $activity])
                @endforeach
            </div>
        </section>
    @endif

    <section class="bg-white border-t border-slate-200">
        <div class="max-w-6xl mx-auto px-6 py-12">
            <h2 class="text-xl font-semibold mb-6 text-center">{{ __('public.home.why_us_title') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
                <div>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_1_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_1_body') }}</p>
                </div>
                <div>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_2_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_2_body') }}</p>
                </div>
                <div>
                    <p class="font-medium text-slate-800 mb-1">{{ __('public.home.why_us_3_title') }}</p>
                    <p class="text-sm text-slate-500">{{ __('public.home.why_us_3_body') }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-[var(--brand-accent)] text-white">
        <div class="max-w-6xl mx-auto px-6 py-12 text-center">
            <h2 class="text-xl font-semibold mb-2">{{ __('public.home.app_cta_title') }}</h2>
            <p class="text-white/90 mb-6">{{ __('public.home.app_cta_body') }}</p>
            <span class="inline-block bg-white text-[var(--brand-primary)] rounded-lg px-6 py-3 font-medium">
                {{ __('public.home.app_cta_button') }}
            </span>
        </div>
    </section>
@endsection
