<a href="{{ route('public.activities.show', $activity->slug) }}"
    class="block bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-lg hover:border-[var(--brand-accent)] transition">
    <div class="aspect-[4/3] bg-slate-100 flex items-center justify-center text-slate-300 text-xs relative">
        @if ($activity->images->isNotEmpty())
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($activity->images->first()->path) }}"
                alt="{{ $activity->title }}" class="w-full h-full object-cover">
        @else
            {{ __('public.activity.no_image') }}
        @endif

        @if ($activity->reviews_count > 0)
            <span
                class="absolute top-3 {{ app()->getLocale() === 'ar' ? 'right-3' : 'left-3' }} bg-white/95 rounded-lg px-2.5 py-1 text-xs font-medium text-amber-600 shadow-sm">
                ★ {{ number_format((float) $activity->average_rating, 1) }}
            </span>
        @endif
    </div>

    <div class="p-5">
        <p class="text-xs text-[var(--brand-accent)] font-medium mb-1">{{ $activity->category->name ?? '' }}</p>
        <p class="font-semibold text-slate-800 mb-2 text-lg">{{ $activity->title }}</p>

        <div class="flex items-center gap-3 text-xs text-slate-500 mb-4">
            @if ($activity->duration_minutes)
                <span>{{ $activity->duration_minutes }} {{ __('public.activity.minutes_short') }}</span>
            @endif
            @if ($activity->reviews_count > 0)
                <span>·</span>
                <span>{{ $activity->reviews_count }} {{ __('public.activity.reviews_label') }}</span>
            @endif
        </div>

        <div class="flex items-center justify-between">
            <div>
                @if ($activity->starting_price !== null)
                    <p class="text-xs text-slate-400">{{ __('public.activity.starting_from') }}</p>
                    <p class="font-semibold text-slate-800">{{ number_format($activity->starting_price, 0) }} EGP</p>
                @endif
            </div>
            <span class="bg-[var(--brand-accent)] text-white text-xs font-medium rounded-lg px-4 py-2">
                {{ __('public.activity.view_details') }}
            </span>
        </div>
    </div>
</a>
