@extends('public.layout')

@section('title', $activity->title . ' — ' . config('branding.name'))
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags($activity->description ?? ''), 155))

@section('content')
    <div class="max-w-6xl mx-auto px-6 py-10">
        <nav class="text-sm text-slate-400 mb-4">
            <a href="{{ route('public.home') }}" class="hover:text-[var(--brand-accent)]">{{ __('public.nav.home') }}</a>
            <span class="mx-1">/</span>
            @if ($activity->category)
                <a href="{{ route('public.categories.show', $activity->category) }}" class="hover:text-[var(--brand-accent)]">
                    {{ $activity->category->name }}
                </a>
                <span class="mx-1">/</span>
            @endif
            <span class="text-slate-600">{{ $activity->title }}</span>
        </nav>

        {{-- Gallery --}}
        <div class="grid grid-cols-4 gap-2 mb-6 rounded-xl overflow-hidden">
            @forelse ($activity->images->take(4) as $image)
                <div class="aspect-video bg-slate-100 {{ $loop->first ? 'col-span-4 md:col-span-2 md:row-span-2' : 'col-span-2 md:col-span-1' }}">
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}"
                        alt="{{ $activity->title }}" class="w-full h-full object-cover">
                </div>
            @empty
                <div class="col-span-4 aspect-video bg-slate-100 flex items-center justify-center text-slate-300">
                    {{ __('public.activity.no_image') }}
                </div>
            @endforelse
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-8">
                <div>
                    <h1 class="text-2xl font-semibold mb-2">{{ $activity->title }}</h1>
                    <div class="flex items-center gap-3 text-sm text-slate-500 mb-4">
                        @if ($activity->reviews_count > 0)
                            <span class="text-amber-500 font-medium">★ {{ number_format((float) $activity->average_rating, 1) }}</span>
                            <span>({{ $activity->reviews_count }} {{ __('public.activity.reviews_label') }})</span>
                        @else
                            <span>{{ __('public.activity.no_reviews_yet') }}</span>
                        @endif
                        @if ($activity->location)
                            <span>·</span>
                            <span>{{ $activity->location }}</span>
                        @endif
                    </div>
                    @if ($activity->description)
                        <p class="text-slate-600 leading-relaxed">{{ $activity->description }}</p>
                    @endif
                </div>

                @if ($activity->featureValues->isNotEmpty())
                    <div>
                        <h2 class="font-semibold text-slate-800 mb-3">{{ __('public.activity.features_title') }}</h2>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            @foreach ($activity->featureValues as $featureValue)
                                <div class="flex justify-between border-b border-slate-100 pb-2">
                                    <span class="text-slate-500">{{ $featureValue->featureDefinition->label }}</span>
                                    <span class="text-slate-800">
                                        @if (is_bool($featureValue->value))
                                            {{ $featureValue->value ? __('public.activity.yes') : __('public.activity.no') }}
                                        @elseif (is_array($featureValue->value))
                                            {{ implode(', ', $featureValue->value) }}
                                        @else
                                            {{ $featureValue->value }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($activity->requirements->isNotEmpty())
                    <div>
                        <h2 class="font-semibold text-slate-800 mb-3">{{ __('public.activity.requirements_title') }}</h2>
                        <ul class="text-sm text-slate-600 space-y-1.5 list-disc ps-5">
                            @foreach ($activity->requirements as $requirement)
                                <li>{{ $requirement->text }}{{ $requirement->is_mandatory ? '' : ' (' . __('public.activity.optional') . ')' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($activity->inclusions->isNotEmpty())
                    <div>
                        <h2 class="font-semibold text-slate-800 mb-3">{{ __('public.activity.inclusions_title') }}</h2>
                        <ul class="text-sm text-slate-600 space-y-1.5">
                            @foreach ($activity->inclusions as $inclusion)
                                <li class="flex items-start gap-2">
                                    <span class="text-[var(--brand-accent)]">{{ $inclusion->type === 'excluded' ? '✕' : '✓' }}</span>
                                    <span>{{ $inclusion->description }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Availability summary — deliberately a static schedule
                     summary, not a live interactive calendar. Real-time
                     availability and booking happen in the mobile app,
                     per the confirmed browse-only scope for this site. --}}
                @if ($activity->schedulePeriods->isNotEmpty())
                    <div>
                        <h2 class="font-semibold text-slate-800 mb-3">{{ __('public.activity.availability_title') }}</h2>
                        <div class="flex flex-wrap gap-2 text-sm">
                            @php
                                $dayNames = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];
                            @endphp
                            @foreach ($activity->schedulePeriods->unique('day_of_week') as $period)
                                <span class="bg-slate-100 rounded-lg px-3 py-1.5 text-slate-600">
                                    {{ __('public.activity.days.' . $dayNames[$period->day_of_week]) }}
                                </span>
                            @endforeach
                        </div>
                        <p class="text-xs text-slate-400 mt-2">{{ __('public.activity.availability_note') }}</p>
                    </div>
                @endif

                {{-- Reviews --}}
                <div>
                    <h2 class="font-semibold text-slate-800 mb-3">{{ __('public.activity.reviews_title') }}</h2>
                    @forelse ($reviews as $review)
                        <div class="border-b border-slate-100 py-4">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-amber-500 text-sm">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
                                <span class="text-sm font-medium text-slate-700">{{ $review->customer->user->name }}</span>
                            </div>
                            @if ($review->comment)
                                <p class="text-sm text-slate-600">{{ $review->comment }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">{{ __('public.activity.no_reviews_yet') }}</p>
                    @endforelse
                    <div class="mt-4">{{ $reviews->links() }}</div>
                </div>
            </div>

            {{-- Booking options / pricing / CTA --}}
            <div>
                <div class="bg-white rounded-xl border border-slate-200 p-6 sticky top-24">
                    <h2 class="font-semibold text-slate-800 mb-4">{{ __('public.activity.booking_options_title') }}</h2>

                    @forelse ($activity->bookingOptions as $option)
                        <div class="border-b border-slate-100 pb-4 mb-4 last:border-0 last:mb-0 last:pb-0">
                            <p class="font-medium text-slate-800">{{ $option->name }}</p>
                            @if ($option->description)
                                <p class="text-xs text-slate-500 mb-2">{{ $option->description }}</p>
                            @endif
                            @foreach ($option->pricingRules as $rule)
                                <div class="flex justify-between text-sm text-slate-600">
                                    <span>{{ __('public.activity.participant_types.' . $rule->participant_type->value) }}</span>
                                    <span class="font-medium">{{ number_format((float) $rule->price, 2) }} {{ $rule->currency }}</span>
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">{{ __('public.activity.no_booking_options') }}</p>
                    @endforelse

                    <span class="block text-center bg-[var(--brand-accent)] text-white rounded-lg px-4 py-3 mt-4 font-medium">
                        {{ __('public.activity.book_via_app') }}
                    </span>
                    <p class="text-xs text-slate-400 text-center mt-2">{{ __('public.activity.book_via_app_note') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
