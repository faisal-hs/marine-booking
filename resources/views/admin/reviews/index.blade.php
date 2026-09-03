@extends('admin.layout')

@section('title', __('admin.reviews.title'))
@section('page_title', __('admin.reviews.title'))

@section('content')
    <form method="GET" class="flex flex-wrap gap-2 mb-4 text-sm">
        <select name="is_hidden" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="">{{ __('admin.reviews.all_statuses') }}</option>
            <option value="0" @selected(($filters['is_hidden'] ?? null) === false)>{{ __('admin.reviews.visible') }}</option>
            <option value="1" @selected(($filters['is_hidden'] ?? null) === true)>{{ __('admin.reviews.hidden') }}</option>
        </select>

        <select name="sort" class="border border-slate-200 rounded-lg px-3 py-1.5">
            <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>{{ __('admin.reservations.newest_first') }}</option>
            <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>{{ __('admin.reservations.oldest_first') }}</option>
        </select>

        <button type="submit" class="bg-slate-100 rounded-lg px-3 py-1.5">{{ __('admin.reservations.filter') }}</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-400 border-b border-slate-100 bg-slate-50">
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.activity') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.customer') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reviews.rating') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.status') }}</th>
                    <th class="py-2.5 px-4 font-medium">{{ __('admin.reservations.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($reviews as $review)
                    <tr class="border-b border-slate-50">
                        <td class="py-2.5 px-4 text-slate-500">{{ $review->activity->title }}</td>
                        <td class="py-2.5 px-4">{{ $review->customer->user->name }}</td>
                        <td class="py-2.5 px-4 text-slate-500">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</td>
                        <td class="py-2.5 px-4">
                            <x-admin.status-badge :variant="$review->is_hidden ? 'danger' : 'success'"
                                :label="__('admin.reviews.' . ($review->is_hidden ? 'hidden' : 'visible'))" />
                        </td>
                        <td class="py-2.5 px-4">
                            <a href="{{ route('admin.reviews.show', $review) }}" class="text-sm text-[var(--brand-accent,theme(colors.slate.700))] underline">
                                {{ __('admin.reservations.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-6 px-4 text-center text-slate-400">{{ __('admin.reviews.no_reviews') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $reviews->links() }}</div>
@endsection
