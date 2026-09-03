@extends('admin.layout')

@section('title', __('admin.reviews.details_title'))
@section('page_title', __('admin.reviews.details_title'))

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reviews.details_title') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.activity') }}</dt>
                        <dd>{{ $review->activity->title }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reviews.rating') }}</dt>
                        <dd>{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}
                            ({{ $review->rating }}/5)</dd>
                    </div>
                    @if ($review->comment)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reviews.comment') }}</dt>
                            <dd>{{ $review->comment }}</dd>
                        </div>
                    @endif
                    @if ($review->is_hidden && $review->hidden_reason)
                        <div>
                            <dt class="text-slate-400">{{ __('admin.reviews.hidden_reason') }}</dt>
                            <dd>{{ $review->hidden_reason }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.created_at') }}</dt>
                        <dd>{{ $review->created_at->toDateTimeString() }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.status') }}</h2>
                <x-admin.status-badge :variant="$review->is_hidden ? 'danger' : 'success'" :label="__('admin.reviews.' . ($review->is_hidden ? 'hidden' : 'visible'))" />

                @can('moderate', \App\Models\Review::class)
                    <div class="mt-4">
                        @if ($review->is_hidden)
                            <form method="POST" action="{{ route('admin.reviews.unhide', $review) }}">
                                @csrf
                                <button type="submit" class="w-full bg-emerald-600 text-white rounded-lg px-3 py-1.5 text-sm">
                                    {{ __('admin.reviews.unhide') }}
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.reviews.hide', $review) }}"
                                onsubmit="return confirm('{{ __('admin.reviews.confirm_hide') }}')">
                                @csrf
                                <input type="text" name="reason" placeholder="{{ __('admin.reviews.hide_reason_placeholder') }}"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-1.5 text-sm mb-2">
                                <button type="submit" class="w-full bg-red-600 text-white rounded-lg px-3 py-1.5 text-sm">
                                    {{ __('admin.reviews.hide') }}
                                </button>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-4">{{ __('admin.reservations.customer') }}</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="text-slate-400">{{ __('admin.reservations.customer_name') }}</dt>
                        <dd>{{ $review->customer->user->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('admin.users.email') }}</dt>
                        <dd>{{ $review->customer->user->email ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
@endsection
