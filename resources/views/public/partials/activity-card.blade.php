<a href="{{ route('public.activities.show', $activity->slug) }}"
    class="block bg-white rounded-xl border border-slate-200 overflow-hidden hover:border-[var(--brand-accent)] transition">
    <div class="aspect-video bg-slate-100 flex items-center justify-center text-slate-300 text-xs">
        @if ($activity->images->isNotEmpty())
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($activity->images->first()->path) }}"
                alt="{{ $activity->title }}" class="w-full h-full object-cover">
        @else
            {{ __('public.activity.no_image') }}
        @endif
    </div>
    <div class="p-4">
        <p class="text-xs text-slate-400 mb-1">{{ $activity->category->name ?? '' }}</p>
        <p class="font-medium text-slate-800 mb-2">{{ $activity->title }}</p>
        <div class="flex items-center justify-between text-sm">
            @if ($activity->reviews_count > 0)
                <span class="text-amber-500">★ {{ number_format((float) $activity->average_rating, 1) }}</span>
                <span class="text-slate-400 text-xs">({{ $activity->reviews_count }})</span>
            @else
                <span class="text-slate-400 text-xs">{{ __('public.activity.no_reviews_yet') }}</span>
            @endif
        </div>
    </div>
</a>
